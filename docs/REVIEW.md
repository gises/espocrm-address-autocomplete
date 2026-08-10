# Photon Address Autocomplete (DACH) – EspoCRM Extension

Adress-Autocomplete für Schweiz, Deutschland und Österreich auf Basis von
[Photon](https://github.com/komoot/photon) (OpenStreetMap).

**Zielversion: EspoCRM 10.x (`acceptableVersions: ["^10.0"]`), PHP 8.3–8.5.
Bewusst nicht rückwärtskompatibel** – alle Kompatibilitäts-Fallbacks für 8.x/9.x
sind entfernt, der Code setzt direkt auf den v10-APIs auf.

---

## 1. Review der ursprünglichen Spezifikation

Vier Punkte der Vorgabe waren technisch nicht haltbar und wurden korrigiert:

| # | Vorgabe | Befund | Umsetzung |
|---|---------|--------|-----------|
| 1 | Endpoint `https://komoot.io` | Falsch – das ist die Marketing-Domain. | `https://photon.komoot.io/api/` |
| 2 | „Photon bietet nativ keinen Länderfilter" | Falsch – Photon kennt den Parameter `countrycode` (ISO-3166-1 alpha-2, **wiederholbar**). | `&countrycode=ch&countrycode=de&countrycode=at`, zusätzlich serverseitiger Filter als zweite Verteidigungslinie |
| 3 | `limit=5` an Photon, danach filtern | Reihenfolgefehler: Filtern nach dem Limit kann 0 Treffer übrig lassen. | Overfetch (`limit × 3`, max. 20), danach filtern, dedupen und auf 5 kürzen |
| 4 | `street` = `properties.name + housenumber` | Photon liefert bei Hausadressen `street` + `housenumber`; `name` ist der POI-/Ortsname. Bei einem Ortstreffer („Berlin") würde „Berlin" als Straße im Formular landen. | `street` mit Fallback auf `name`, aber **nicht** bei `osm_key=place` |

Weitere Anpassungen:

- **CORS entfällt.** Als EspoCRM-Route läuft der Proxy same-origin unter
  `/api/v1/PhotonAddress/search` und ist durch die reguläre Espo-Auth geschützt.
  Ein externer Proxy mit `Access-Control-Allow-Origin: *` wäre ein offenes
  Relay auf Kosten der öffentlichen Photon-Instanz.
- **`city`-Fallback** auf `district` / `county`, weil Photon `city` nicht
  immer setzt.
- **Ergebnis-Cache** (Default 24 h, dateibasiert) – Autocomplete erzeugt sonst
  pro getipptem Zeichen einen Request.
- **Fehlertoleranz**: Ist Photon nicht erreichbar, liefert der Endpoint ein
  leeres Array statt eines Fehlers. Ein ausgefallener Geocoder darf das
  Formular nicht blockieren.

> **Produktivbetrieb:** `photon.komoot.io` wird von komoot kostenlos und ohne
> SLA bereitgestellt. Für dauerhaften CRM-Einsatz ist eine eigene
> Photon-Instanz (Docker, DACH-Extrakt ≈ wenige GB) die saubere Lösung –
> danach nur `photonAddressUrl` in der Config umstellen.

---

## 2. Paketinhalt (Inhalt der gebauten ZIP)

```
<paket>.zip
├── manifest.json
├── scripts/
│   └── AfterInstall.php                     Default-Config schreiben
└── files/
    ├── custom/Espo/Modules/PhotonAddress/
    │   ├── Api/GetSearch.php                Route-Handler (Validierung)
    │   ├── Tools/Photon/
    │   │   ├── PhotonConfig.php             Konfiguration + Defaults
    │   │   ├── PhotonClient.php             HTTP (cURL)
    │   │   ├── ResultMapper.php             GeoJSON → flaches CRM-Objekt
    │   │   └── SearchService.php            Orchestrierung + Cache
    │   └── Resources/
    │       ├── module.json
    │       ├── routes.json
    │       └── metadata/entityDefs/         Account, Contact, Lead
    └── client/custom/modules/photon-address/src/views/fields/
        └── address-autocomplete.js          Feld-View auf ui/autocomplete
```

Keine Composer- oder NPM-Abhängigkeiten. `ext-curl` und `ext-json` sind
ohnehin EspoCRM-Systemvoraussetzungen.

### Gegen v10 verifizierte Annahmen

Die Extension wurde gegen den Quellcode von EspoCRM 10.0.1 geprüft:

- `Espo\Core\Api\Action` / `ResponseComposer::json()` / `Request::getQueryParam()`
  – unverändert.
- `routes.json` mit `actionClassName` – weiterhin unterstützt
  (`RouteProcessor::processAction`).
- Install-Skripte werden mit `run($container, $params)` aufgerufen – die
  Signatur nimmt beide Argumente.
- Das Address-Feld ist in v10 TypeScript (`client/src/views/fields/address.ts`).
  Relevant: die Subfeld-Attribute liegen als `streetField`, `postalCodeField`,
  `cityField`, `stateField`, `countryField` vor, die Inputs tragen
  `data-name="<name>Street"` usw., und **`fetch()` liest direkt aus dem DOM**.
  Ein reines `model.set()` würde beim Speichern überschrieben – die View
  beschreibt deshalb die Inputs und feuert `trigger('change')`.
- Das Straßenfeld ist eine `<textarea>` (`edit.tpl`), keine `<input>`.
- **Modulformat AMD.** Der Loader (`loader.js::_idToPath`) lädt
  `client/custom/modules/<mod>/src/…` als AMD-Skript. ESM würde
  `"jsTranspiled": true` plus einen Transpile-Build (`espo-frontend-build-tools`,
  nicht öffentlich auf npm) voraussetzen – für eine einzelne Datei nicht
  sinnvoll.
- Fehlerfall: `xhr.errorIsHandled = true` unterdrückt den globalen
  Fehlerdialog, falls der Endpoint 401/500 liefert.

---

## 3. Installation

1. Administration → Extensions → ZIP hochladen → Install.
2. Administration → Clear Cache / Rebuild.
3. Testen:
   `GET https://<crm>/api/v1/PhotonAddress/search?q=Bahnhofstrasse%208001`
   (eingeloggt im Browser oder per API-Key).

Aktiv auf: Account (Rechnungs- und Lieferadresse), Contact, Lead.

Weitere Adressfelder aktivieren – Datei
`custom/Espo/Custom/Resources/metadata/entityDefs/<Entity>.json`:

```json
{
    "fields": {
        "meinAdressFeld": {
            "view": "photon-address:views/fields/address-autocomplete"
        }
    }
}
```

---

## 4. Konfiguration (`data/config.php`)

| Parameter | Default | Bedeutung |
|-----------|---------|-----------|
| `photonAddressUrl` | `https://photon.komoot.io/api/` | Endpoint, für Self-Hosting ändern |
| `photonAddressCountryCodes` | – (Adminliste) | manueller Länder-Override; ohne Eintrag gelten die bevorzugten Länder aus Administration → Adresse Länder, Fallback `['ch','de','at']` |
| `photonAddressLang` | `de` | Sprache der Bezeichnungen |
| `photonAddressLimit` | `5` | ausgelieferte Treffer |
| `photonAddressTimeout` | `4` | Sekunden |
| `photonAddressCacheTtl` | `86400` | Sekunden, `0` = aus |
| `photonAddressLayers` | `[]` | z. B. `['house','street']` für reine Adresstreffer |

---

## 5. Antwortformat

```json
[
  {
    "label": "Bahnhofstrasse 8 – 8001 Zürich (Zürich), Schweiz",
    "street": "Bahnhofstrasse 8",
    "zip": "8001",
    "city": "Zürich",
    "state": "Zürich",
    "country": "Schweiz",
    "countryCode": "CH",
    "lat": 47.3739,
    "lon": 8.5401,
    "osmId": "1"
  }
]
```

`(Kanton/Bundesland)` wird weggelassen, wenn `state` gleich `city` ist
(Stadtkantone wie Basel-Stadt, Stadtstaaten wie Berlin/Wien).

---

## 6. Offene Punkte für den Produktivbetrieb

- **Rate Limiting pro Nutzer** ist nicht enthalten. Der Cache dämpft die Last;
  bei vielen gleichzeitigen Nutzern zusätzlich drosseln oder self-hosten.
- **Cache-Ordner** `data/cache/photon-address` wächst; er wird beim
  Espo-Rebuild nicht automatisch geleert. Optional per Cron aufräumen.
- **Lizenz/Attribution**: Die Daten stammen aus OpenStreetMap (ODbL).
  Ein Hinweis „© OpenStreetMap-Mitwirkende" im Formular ist angebracht.
