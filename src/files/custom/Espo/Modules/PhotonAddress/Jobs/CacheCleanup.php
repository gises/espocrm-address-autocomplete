<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Log;
use Espo\Modules\PhotonAddress\Tools\Photon\PhotonConfig;

/**
 * Loescht abgelaufene Eintraege aus dem Suchcache. Der Ordner
 * data/cache/photon-address wird weder vom Espo-Rebuild noch vom
 * Cache-Leeren im Admin beruehrt und wuerde sonst unbegrenzt wachsen.
 *
 * Angemeldet ueber app/scheduledJobs.json; den ScheduledJob-Datensatz
 * legt AfterInstall an (Espos Populator laeuft nur per Konsole).
 */
class CacheCleanup implements JobDataLess
{
    private const string CACHE_DIR = 'data/cache/photon-address';

    public function __construct(
        private readonly FileManager $fileManager,
        private readonly PhotonConfig $photonConfig,
        private readonly Log $log
    ) {}

    public function run(): void
    {
        if (!$this->fileManager->isDir(self::CACHE_DIR)) {
            return;
        }

        // TTL 0 bedeutet "Cache deaktiviert" - dann ist jeder noch
        // vorhandene Eintrag ein Altlast-Kandidat (deadline = jetzt).
        $deadline = time() - $this->photonConfig->getCacheTtl();

        /** @var string[] $files */
        $files = $this->fileManager->getFileList(self::CACHE_DIR, false, '\.json$', true);

        $removed = 0;

        foreach ($files as $file) {
            $path = self::CACHE_DIR . '/' . $file;

            $mTime = @filemtime($path);

            // Nicht lesbare mtime: Datei ist verwaist oder kaputt - weg damit.
            if ($mTime !== false && $mTime > $deadline) {
                continue;
            }

            if ($this->fileManager->removeFile($path)) {
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->log->info("PhotonAddress: cache cleanup removed {$removed} file(s).");
        }
    }
}
