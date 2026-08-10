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

        $bias = $context !== [] ? $this->resolveBias($context) : null;

        try {
            // Bewusst mehr Treffer anfragen als ausgeliefert werden:
            // der defensive Laenderfilter und die Deduplizierung koennen
            // Eintraege entfernen. Bei limit=5 direkt an Photon koennte
            // sonst eine leere Liste zurueckkommen.
            $features = $this->client->search($q, min($limit * 3, 20), $bias);
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

            if ($item === null) {
                continue;
            }

            $signature = mb_strtolower((string) $item['label']);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $results[] = $item;
        }

        if ($context !== []) {
            // Der Kontext gewichtet, statt zu filtern: Treffer aus dem
            // eingegebenen Ort bzw. Land stehen oben, abweichende bleiben
            // aber waehlbar - wer trotz gefuelltem Ort eine Adresse
            // anderswo sucht, bekommt sie weiterhin angeboten.
            // usort ist seit PHP 8.0 stabil, die Photon-Reihenfolge
            // bleibt innerhalb gleicher Gewichtung erhalten.
            usort(
                $results,
                fn (array $a, array $b) =>
                    $this->contextScore($b, $context) <=> $this->contextScore($a, $context)
            );
        }

        $results = array_slice($results, 0, $limit);

        $this->writeCache($cacheKey, $results);

        return $results;
    }

    /**
     * Ermittelt den Ankerpunkt fuer Photons Location-Bias aus PLZ/Ort/Land.
     *
     * Der Kontext wird bewusst NICHT in den Suchtext gemischt: Photons
     * Volltext-Matching laesst sonst die Ortsangabe dominieren und liefert
     * beliebige Strassen im Kontext-Ort statt des getippten Namens
     * ("Grubstrasse 14" + Mainz ergab "Josefsstrasse 14, Mainz" usw.).
     * Stattdessen wird der Ort einmal geokodiert (gecacht) und als
     * lat/lon-Bias uebergeben - Photon gewichtet dann selbst nach Naehe,
     * ohne Treffer anderswo auszuschliessen.
     *
     * @param array<string, string> $context
     * @return array{lat: float, lon: float}|null
     */
    private function resolveBias(array $context): ?array
    {
        $locationQuery = trim(($context['zip'] ?? '') . ' ' . ($context['city'] ?? ''));

        if (($context['country'] ?? '') !== '') {
            $locationQuery = trim($locationQuery . ', ' . $context['country'], ' ,');
        }

        if ($locationQuery === '') {
            return null;
        }

        $cacheKey = sha1(implode('|', [
            'location',
            mb_strtolower($locationQuery),
            implode(',', $this->photonConfig->getCountryCodes()),
            $this->photonConfig->getUrl(),
        ]));

        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return isset($cached['lat'], $cached['lon'])
                ? ['lat' => (float) $cached['lat'], 'lon' => (float) $cached['lon']]
                : null;
        }

        try {
            $features = $this->client->search($locationQuery, 1);
        }
        catch (Throwable $e) {
            // Ohne Bias laeuft die Suche einfach ungewichtet weiter.
            $this->log->warning('PhotonAddress: bias lookup failed. ' . $e->getMessage());

            return null;
        }

        $coordinates = $features[0]['geometry']['coordinates'] ?? null;

        if (
            !is_array($coordinates)
            || !isset($coordinates[0], $coordinates[1])
            || !is_numeric($coordinates[0])
            || !is_numeric($coordinates[1])
        ) {
            // Auch "nicht aufloesbar" cachen, sonst geokodiert jeder
            // Tastendruck denselben unbrauchbaren Kontext erneut.
            $this->writeCache($cacheKey, []);

            return null;
        }

        // GeoJSON-Koordinaten sind [lon, lat].
        $bias = ['lat' => (float) $coordinates[1], 'lon' => (float) $coordinates[0]];

        $this->writeCache($cacheKey, $bias);

        return $bias;
    }

    /**
     * Gewicht eines Treffers relativ zum Formularkontext: Ort zaehlt
     * staerker als Land. Der Vergleich ist exakt (case-insensitiv) -
     * Autofill schreibt und Photon liefert dieselbe Sprache
     * (lang-Parameter), daher passt "Italien" zu "Italien". Die PLZ
     * geht bewusst nicht ins Gewicht ein: Strassenzuege koennen
     * mehrere PLZ tragen.
     *
     * @param array<string, mixed> $item
     * @param array<string, string> $context
     */
    private function contextScore(array $item, array $context): int
    {
        $score = 0;

        foreach (['city' => 2, 'country' => 1] as $key => $weight) {
            $expected = $context[$key] ?? '';
            $actual = $item[$key] ?? null;

            if (
                $expected !== ''
                && is_string($actual)
                && mb_strtolower($actual) === mb_strtolower($expected)
            ) {
                $score += $weight;
            }
        }

        return $score;
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
            $this->photonConfig->getBiasZoom(),
            $this->photonConfig->getBiasScale(),
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
