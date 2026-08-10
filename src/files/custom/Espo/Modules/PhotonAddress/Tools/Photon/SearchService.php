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
     * @return array<int, array<string, mixed>>
     */
    public function search(string $q, ?int $limitOverride = null): array
    {
        $limit = $this->photonConfig->getLimit($limitOverride);
        $cacheKey = $this->buildCacheKey($q, $limit);

        $cached = $this->readCache($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        try {
            // Bewusst mehr Treffer anfragen als ausgeliefert werden:
            // der defensive DACH-Filter und die Deduplizierung koennen
            // Eintraege entfernen. Bei limit=5 direkt an Photon koennte
            // sonst eine leere Liste zurueckkommen.
            $features = $this->client->search($q, min($limit * 3, 20));
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

            if (count($results) >= $limit) {
                break;
            }
        }

        $this->writeCache($cacheKey, $results);

        return $results;
    }

    private function buildCacheKey(string $q, int $limit): string
    {
        return sha1(implode('|', [
            mb_strtolower($q),
            $limit,
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
