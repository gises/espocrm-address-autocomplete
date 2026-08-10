<?php

/**
 * Testet den PHP-Mapper gegen die Fixtures in tests/fixtures/features.json.
 *
 * ResultMapper hat bewusst keine EspoCRM-Abhaengigkeiten und laesst sich
 * daher ohne laufende Espo-Installation pruefen.
 *
 * Ausfuehren:  php tests/mapper.test.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/files/custom/Espo/Modules/PhotonAddress/Tools/Photon/ResultMapper.php';

use Espo\Modules\PhotonAddress\Tools\Photon\ResultMapper;

$features = json_decode(file_get_contents(__DIR__ . '/fixtures/features.json'), true);

$mapper = new ResultMapper();
$allowed = ['ch', 'de', 'at'];

$results = [];

foreach ($features as $feature) {
    $item = $mapper->map($feature, $allowed);

    if ($item !== null) {
        $results[] = $item;
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n\n";

$failures = 0;

$assert = function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "  ok   {$message}\n";

        return;
    }

    echo "  FAIL {$message}\n";
    $failures++;
};

$assert(count($results) === 3, 'FR-Treffer und unbrauchbarer Eintrag werden gefiltert');
$assert($results[0]['label'] === 'Bahnhofstrasse 8 – 8001 Zürich, Schweiz', 'Label CH (state == city wird weggelassen)');
$assert($results[0]['street'] === 'Bahnhofstrasse 8', 'Strasse und Hausnummer zusammengefuegt');
$assert($results[0]['zip'] === '8001', 'PLZ');
$assert($results[0]['city'] === 'Zürich', 'Ort');
$assert($results[0]['state'] === 'Zürich', 'Kanton');
$assert($results[0]['country'] === 'Schweiz', 'Land');
$assert($results[0]['countryCode'] === 'CH', 'Laendercode');
$assert($results[1]['street'] === null, 'Ortstreffer setzt keine Strasse');
$assert($results[1]['label'] === '10117 Berlin, Deutschland', 'Label DE Ortstreffer');
$assert($results[2]['street'] === 'Kärntner Straße', 'name als Fallback fuer street');
$assert($results[2]['label'] === 'Kärntner Straße – 1010 Wien, Österreich', 'Label AT');

echo "\n";

if ($failures > 0) {
    fwrite(STDERR, "{$failures} Assertion(s) fehlgeschlagen.\n");
    exit(1);
}

echo "PHP-Mapper: alle Assertions bestanden.\n";
