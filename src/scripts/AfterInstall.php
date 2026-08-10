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

        $defaults = [
            'photonAddressUrl' => 'https://photon.komoot.io/api/',
            'photonAddressCountryCodes' => ['ch', 'de', 'at'],
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

        if ($isChanged) {
            $configWriter->save();
        }
    }
}
