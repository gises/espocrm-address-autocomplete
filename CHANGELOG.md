# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

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
