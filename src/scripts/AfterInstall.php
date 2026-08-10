<?php

use Espo\Core\Container;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

/**
 * Legt die Default-Konfiguration an, ohne bestehende Werte zu ueberschreiben.
 * So bleibt eine vorhandene Self-Hosted-Photon-URL bei einem Update erhalten.
 */
class AfterInstall
{
    /**
     * @param array<string, mixed> $params
     */
    public function run(Container $container, array $params = []): void
    {
        /** @var Config $config */
        $config = $container->get('config');

        /** @var ConfigWriter $configWriter */
        $configWriter = $container->get('injectableFactory')->create(ConfigWriter::class);

        // photonAddressCountryCodes wird bewusst NICHT mehr vorbelegt:
        // die Laender kommen aus der Standardliste (Administration >
        // Adresse Laender, Flag "Wird bevorzugt"). Der Config-Schluessel
        // bleibt als manueller Override moeglich.
        $defaults = [
            'photonAddressUrl' => 'https://photon.komoot.io/api/',
            'photonAddressLang' => 'de',
            'photonAddressLimit' => 5,
            'photonAddressTimeout' => 4,
            'photonAddressCacheTtl' => 86400,
            'photonAddressLayers' => [],
        ];

        $isChanged = false;

        foreach ($defaults as $key => $value) {
            if ($config->get($key) === null) {
                $configWriter->set($key, $value);
                $isChanged = true;
            }
        }

        // Migration von <= 1.0.x: dort hat AfterInstall ['ch','de','at']
        // in die Config geschrieben. Genau dieser unveraenderte Default
        // wird entfernt, damit die Adminliste greift. Ein vom Admin
        // bewusst gesetzter (abweichender) Wert bleibt bestehen.
        //
        // Laeuft nur ein einziges Mal (Marker-Flag): seit es die
        // Admin-Seite gibt, kann ch/de/at auch eine bewusste Eingabe
        // sein - die darf ein spaeteres Update nicht mehr wegraeumen.
        if ($config->get('photonAddressCountryCodesMigrated') === null) {
            if ($config->get('photonAddressCountryCodes') === ['ch', 'de', 'at']) {
                $configWriter->remove('photonAddressCountryCodes');
            }

            $configWriter->set('photonAddressCountryCodesMigrated', true);
            $isChanged = true;
        }

        if ($isChanged) {
            $configWriter->save();
        }
    }
}
