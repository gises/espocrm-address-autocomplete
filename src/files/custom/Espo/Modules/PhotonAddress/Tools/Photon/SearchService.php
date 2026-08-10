<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Tools\Photon;

use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * Orchestriert Abfrage, Filterung, Mapping und Caching.
 */
class SearchService
{
    private const string CACHE_DIR = 'data/cache/photon-address';

    public function __construct(
        private readonly PhotonClient $client,
        private readonly ResultMapper $mapper,
        private readonly PhotonConfig $photonConfig,
        private readonly FileManager $fileManager,
        private readonly Log $log
    ) {}

    /**
     * @param array{city?: string, zip?: string, country?: string} $context
     *        Bereits ausgefuellte Subfelder des Adressformulars.
     * @return array<int, array<string, mixed>>
     */
    public function search(string $q, ?int $limitOverride = null, array $context = []): array
    {
        $limit = $this->photonConfig->getLimit($limitOverride);
        $context = $this->normalizeContext($context);

        $results = $this->doSearch($q, $limit, $context);

        // Der Kontext kann zu praezise sein (von Hand editierter Ort,
        // widerspruechliche Eingaben). Dann lieber breit suchen als dem
        // Nutzer eine leere Liste zeigen.
        if ($results === [] && $context !== []) {
            $results = $this->doSearch($q, $limit, []);
        }

        return $results;
    }

    /**
     * @param array<string, string> $context
     * @return array<int, array<string, mixed>>
     */
    private function doSearch(string $q, int $limit, array $context): array
    {
        $cacheKey = $this->buildCacheKey($q, $limit, $context);

        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            // Bewusst mehr Treffer anfragen als ausgeliefert werden:
            // der defensive Laenderfilter und die Deduplizierung koennen
            // Eintraege entfernen. Bei limit=5 direkt an Photon koennte
            // sonst eine leere Liste zurueckkommen.
            $features = $this->client->search($this->buildPhotonQuery($q, $context), min($limit * 3, 20));
        }
        catch (Throwable $e) {
            $this->log->error('PhotonAddress: ' . $e->getMessage());

            // Ein ausgefallener Geocoder darf das Formular nicht blockieren.
            return [];
        }

        $countryCodes = $this->photonConfig->getCountryCodes();

        $results = [];
        $seen = [];

        foreach ($features as $feature) {
            $item = $this->mapper->map($feature, $countryCodes);

            if ($item === null || !$this->matchesContext($item, $context)) {
                continue;
            }

            $signature = mb_strtolower((string) $item['label']);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $results[] = $item;

            if (count($results) >= $limit) {
                break;
            }
        }

        $this->writeCache($cacheKey, $results);

        return $results;
    }

    /**
     * Photon kennt auf der oeffentlichen Instanz keine strukturierte Suche;
     * der Kontext wird deshalb als Volltext an die Query gehaengt und
     * hebt Treffer aus dem richtigen Ort ueber das Ranking nach oben.
     *
     * @param array<string, string> $context
     */
    private function buildPhotonQuery(string $q, array $context): string
    {
        $parts = [$q];

        $zipCity = trim(($context['zip'] ?? '') . ' ' . ($context['city'] ?? ''));

        if ($zipCity !== '') {
            $parts[] = $zipCity;
        }

        if (($context['country'] ?? '') !== '') {
            $parts[] = $context['country'];
        }

        return implode(', ', $parts);
    }

    /**
     * Zweite Verteidigungslinie zum Query-Ranking: Treffer aus dem
     * falschen Ort bzw. Land fliegen raus. Der Vergleich ist exakt
     * (case-insensitiv) - Autofill schreibt und Photon liefert dieselbe
     * Sprache (lang-Parameter), daher passt "Italien" zu "Italien".
     * Auf die PLZ wird bewusst nicht gefiltert: Strassenzuege koennen
     * mehrere PLZ tragen.
     *
     * @param array<string, mixed> $item
     * @param array<string, string> $context
     */
    private function matchesContext(array $item, array $context): bool
    {
        foreach (['city' => 'city', 'country' => 'country'] as $contextKey => $itemKey) {
            $expected = $context[$contextKey] ?? '';
            $actual = $item[$itemKey] ?? null;

            if ($expected === '' || !is_string($actual)) {
                continue;
            }

            if (mb_strtolower($actual) !== mb_strtolower($expected)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, string>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach (['city', 'zip', 'country'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, string> $context
     */
    private function buildCacheKey(string $q, int $limit, array $context): string
    {
        return sha1(implode('|', [
            mb_strtolower($q),
            $limit,
            mb_strtolower($context['city'] ?? ''),
            mb_strtolower($context['zip'] ?? ''),
            mb_strtolower($context['country'] ?? ''),
            $this->photonConfig->getLang(),
            implode(',', $this->photonConfig->getCountryCodes()),
            implode(',', $this->photonConfig->getLayers()),
            $this->photonConfig->getUrl(),
        ]));
    }

    private function getCachePath(string $key): string
    {
        return self::CACHE_DIR . '/' . $key . '.json';
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    private function readCache(string $key): ?array
    {
        $ttl = $this->photonConfig->getCacheTtl();

        if ($ttl === 0) {
            return null;
        }

        $path = $this->getCachePath($key);

        if (!$this->fileManager->isFile($path)) {
            return null;
        }

        $mTime = @filemtime($path);

        if ($mTime === false || (time() - $mTime) > $ttl) {
            return null;
        }

        $contents = $this->fileManager->getContents($path);
        $data = json_decode($contents, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    private function writeCache(string $key, array $results): void
    {
        if ($this->photonConfig->getCacheTtl() === 0) {
            return;
        }

        try {
            $this->fileManager->putContents(
                $this->getCachePath($key),
                json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }
        catch (Throwable $e) {
            // Cache ist optional - ein Schreibfehler darf die Suche nicht kippen.
            $this->log->warning('PhotonAddress: cache write failed. ' . $e->getMessage());
        }
    }
}
