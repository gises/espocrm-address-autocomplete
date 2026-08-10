<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Tools\Photon;

use Espo\Core\Utils\Log;
use RuntimeException;

/**
 * Duenner HTTP-Client fuer die Photon-API.
 *
 * Bewusst cURL statt Guzzle: ext-curl ist eine harte Systemvoraussetzung
 * von EspoCRM und immer vorhanden, waehrend ein zusaetzliches
 * Composer-Paket in einer Extension nicht sauber nachinstalliert werden
 * kann (der vendor-Ordner gehoert dem Core).
 */
class PhotonClient
{
    public function __construct(
        private readonly PhotonConfig $photonConfig,
        private readonly Log $log
    ) {}

    /**
     * @return array<int, array<string, mixed>> Die GeoJSON-Features.
     * @throws RuntimeException
     */
    public function search(string $q, int $limit): array
    {
        $url = $this->buildUrl($q, $limit);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->photonConfig->getTimeout(),
            CURLOPT_TIMEOUT => $this->photonConfig->getTimeout(),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            // Fairer Umgang mit der oeffentlichen Instanz: identifizierbarer Agent.
            CURLOPT_USERAGENT => 'EspoCRM-PhotonAddress/1.0 (+address autocomplete)',
        ]);

        $body = curl_exec($ch);
        $errorNo = curl_errno($ch);
        $errorMessage = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        curl_close($ch);

        if ($errorNo !== 0 || $body === false) {
            throw new RuntimeException("Photon request failed: {$errorMessage}");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new RuntimeException("Photon returned HTTP {$statusCode}.");
        }

        $data = json_decode((string) $body, true);

        if (!is_array($data)) {
            throw new RuntimeException('Photon returned malformed JSON.');
        }

        $features = $data['features'] ?? null;

        if (!is_array($features)) {
            $this->log->warning('PhotonAddress: response without "features" key.');

            return [];
        }

        return array_values(array_filter($features, 'is_array'));
    }

    private function buildUrl(string $q, int $limit): string
    {
        $parameters = [
            'q' => $q,
            'lang' => $this->photonConfig->getLang(),
            'limit' => (string) $limit,
        ];

        $query = http_build_query($parameters);

        // countrycode und layer sind wiederholbare Parameter und lassen
        // sich daher nicht ueber http_build_query abbilden.
        foreach ($this->photonConfig->getCountryCodes() as $code) {
            $query .= '&countrycode=' . urlencode($code);
        }

        foreach ($this->photonConfig->getLayers() as $layer) {
            $query .= '&layer=' . urlencode($layer);
        }

        $separator = str_contains($this->photonConfig->getUrl(), '?') ? '&' : '?';

        return $this->photonConfig->getUrl() . $separator . $query;
    }
}
