# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [1.1.0] – 2026-08-10

### Neu

- Die teilnehmenden Länder steuert jetzt die Standardliste unter
  **Administration → Adresse Länder**: alle Einträge mit dem Flag
  **«Wird bevorzugt»** werden als `countrycode`-Filter an Photon übergeben
  und serverseitig als zweite Verteidigungslinie geprüft.
  Auswertungsreihenfolge: `photonAddressCountryCodes` in `data/config.php`
  (manueller Override) → bevorzugte Länder der Adminliste → Fallback
  `['ch','de','at']`. Die Abfrage der Länderliste ist pro Request
  memoisiert; ein Fehler beim Lesen kippt das Autocomplete nicht,
  sondern fällt auf den DACH-Default zurück.

### Geändert

- `AfterInstall` belegt `photonAddressCountryCodes` nicht mehr vor.
  Migration: Steht in der Config exakt der alte Auto-Default
  `['ch','de','at']`, wird der Schlüssel entfernt, damit die Adminliste
  greift. Ein bewusst abweichend gesetzter Wert bleibt erhalten.

## [1.0.1] – 2026-08-10

### Behoben

- Die Vorschlagsliste blieb nach der Übernahme eines Treffers sichtbar.
  Ursache: `devbridge-autocomplete` ruft in `onFocus()` erneut
  `onValueChange()` auf, sobald der Feldwert mindestens `minChars` Zeichen
  hat. Da Espos Autocomplete-Wrapper nach der Auswahl `focus()` auslöst,
  startete sofort eine neue Suche mit dem gerade übernommenen Straßenwert.
  Die Feldansicht merkt sich den übernommenen Wert jetzt und liefert dafür
  eine leere Trefferliste – `suggest()` ruft daraufhin `hide()` auf. Gleiches
  Prinzip wie beim `lookupFilter` der Core-Felder für Land, Ort und Kanton.

## [1.0.0] – 2026-08-10

### Neu

- Serverseitiger Proxy-Endpoint `GET /api/v1/PhotonAddress/search`,
  authentifiziert über die reguläre EspoCRM-Session (kein CORS nötig).
- Filterung auf CH, DE und AT über den wiederholbaren Photon-Parameter
  `countrycode`, zusätzlich serverseitig als zweite Verteidigungslinie.
- Mapping der GeoJSON-Antwort auf `label`, `street`, `zip`, `city`, `state`,
  `country`, `countryCode`, `lat`, `lon`, `osmId`.
- Feldansicht auf Basis von `ui/autocomplete` mit automatischer Befüllung
  von Straße, PLZ, Ort, Kanton/Bundesland und Land.
- Aktiv auf Account (Rechnungs- und Lieferadresse), Contact und Lead.
- Dateibasierter Ergebnis-Cache, Standard 24 Stunden.
- Node.js-Referenzimplementierung für den Betrieb außerhalb von EspoCRM
  unter `contrib/serverless/`.
