<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Tools\Photon;

use Espo\Core\Utils\Config;

/**
 * Liest die Einstellungen aus data/config.php bzw. config-internal.php.
 * Alle Werte haben sinnvolle Defaults, die Extension laeuft also auch
 * ohne jede Konfiguration.
 *
 * Ueberschreibbar in data/config.php:
 *
 *   'photonAddressUrl'          => 'https://photon.komoot.io/api/',
 *   'photonAddressCountryCodes' => ['ch', 'de', 'at'],
 *   'photonAddressLang'         => 'de',
 *   'photonAddressLimit'        => 5,
 *   'photonAddressTimeout'      => 4,
 *   'photonAddressCacheTtl'     => 86400,
 *   'photonAddressLayers'       => ['house', 'street'],
 */
class PhotonConfig
{
    private const string DEFAULT_URL = 'https://photon.komoot.io/api/';
    private const array DEFAULT_COUNTRY_CODES = ['ch', 'de', 'at'];
    private const string DEFAULT_LANG = 'de';
    private const int DEFAULT_LIMIT = 5;
    private const int DEFAULT_TIMEOUT = 4;
    private const int DEFAULT_CACHE_TTL = 86400;
    private const int MAX_LIMIT = 20;

    public function __construct(
        private readonly Config $config
    ) {}

    public function getUrl(): string
    {
        $url = (string) ($this->config->get('photonAddressUrl') ?: self::DEFAULT_URL);

        return rtrim($url, '?&');
    }

    /**
     * @return string[] Kleingeschriebene ISO-3166-1-alpha-2-Codes.
     */
    public function getCountryCodes(): array
    {
        $value = $this->config->get('photonAddressCountryCodes');

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value) || $value === []) {
            $value = self::DEFAULT_COUNTRY_CODES;
        }

        $list = [];

        foreach ($value as $code) {
            $code = strtolower(trim((string) $code));

            if (preg_match('/^[a-z]{2}$/', $code)) {
                $list[] = $code;
            }
        }

        return $list !== [] ? array_values(array_unique($list)) : self::DEFAULT_COUNTRY_CODES;
    }

    public function getLang(): string
    {
        $lang = strtolower(trim((string) ($this->config->get('photonAddressLang') ?: self::DEFAULT_LANG)));

        return preg_match('/^[a-z]{2}$/', $lang) ? $lang : self::DEFAULT_LANG;
    }

    public function getLimit(?int $override = null): int
    {
        $limit = $override ?? (int) ($this->config->get('photonAddressLimit') ?: self::DEFAULT_LIMIT);

        return max(1, min($limit, self::MAX_LIMIT));
    }

    public function getTimeout(): int
    {
        $timeout = (int) ($this->config->get('photonAddressTimeout') ?: self::DEFAULT_TIMEOUT);

        return max(1, min($timeout, 15));
    }

    public function getCacheTtl(): int
    {
        $ttl = $this->config->get('photonAddressCacheTtl');

        return $ttl === null ? self::DEFAULT_CACHE_TTL : max(0, (int) $ttl);
    }

    /**
     * @return string[] Leeres Array = kein Layer-Filter.
     */
    public function getLayers(): array
    {
        $value = $this->config->get('photonAddressLayers');

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (!is_array($value)) {
            return [];
        }

        $allowed = ['house', 'street', 'locality', 'district', 'city', 'county', 'state', 'country', 'other'];
        $list = [];

        foreach ($value as $layer) {
            $layer = strtolower(trim((string) $layer));

            if (in_array($layer, $allowed, true)) {
                $list[] = $layer;
            }
        }

        return array_values(array_unique($list));
    }
}
