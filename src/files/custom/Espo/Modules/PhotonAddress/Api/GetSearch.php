<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Api;

use Espo\Core\Api\Action;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Api\ResponseComposer;
use Espo\Modules\PhotonAddress\Tools\Photon\SearchService;

/**
 * GET /api/v1/PhotonAddress/search?q=...&city=...&zip=...&country=...
 *
 * Serverseitiger Proxy fuer die Photon-Geocoding-API (OpenStreetMap).
 * Die Route ist ueber die regulaere EspoCRM-Authentifizierung geschuetzt,
 * d. h. nur eingeloggte Nutzer bzw. gueltige API-Keys koennen sie aufrufen.
 * Dadurch entfaellt jede CORS-Konfiguration (Same-Origin) und der Proxy
 * kann nicht als offener Relay missbraucht werden.
 *
 * city, zip und country sind optionaler Kontext aus bereits ausgefuellten
 * Subfeldern des Adressformulars; sie engen die Suche ein.
 *
 * Antwort: flaches JSON-Array mit den Keys
 * label, street, houseNumber, numberFirst, zip, city, state, country,
 * countryCode, lat, lon, osmId.
 */
class GetSearch implements Action
{
    private const int MIN_QUERY_LENGTH = 3;
    private const int MAX_QUERY_LENGTH = 150;
    private const int MAX_CONTEXT_LENGTH = 100;

    public function __construct(
        private readonly SearchService $searchService
    ) {}

    public function process(Request $request): Response
    {
        $q = trim((string) ($request->getQueryParam('q') ?? ''));

        // Anforderung 1: unter 3 Zeichen sofort abbrechen.
        // Bewusst 200 + leeres Array statt 400 - ein Autocomplete-Feld
        // feuert waehrend des Tippens zwangslaeufig kurze Queries ab,
        // die im Frontend keine Fehlerbehandlung ausloesen sollen.
        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return ResponseComposer::json([]);
        }

        if (mb_strlen($q) > self::MAX_QUERY_LENGTH) {
            $q = mb_substr($q, 0, self::MAX_QUERY_LENGTH);
        }

        $limitParam = $request->getQueryParam('limit');
        $limit = is_numeric($limitParam) ? (int) $limitParam : null;

        $context = [];

        foreach (['city', 'zip', 'country'] as $key) {
            $value = trim((string) ($request->getQueryParam($key) ?? ''));

            if ($value !== '') {
                $context[$key] = mb_substr($value, 0, self::MAX_CONTEXT_LENGTH);
            }
        }

        $results = $this->searchService->search($q, $limit, $context);

        return ResponseComposer::json($results);
    }
}
