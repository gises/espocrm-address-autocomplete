# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [1.3.0] – 2026-08-10

### Neu

- Einstellungsseite **Administration → Photon Address Autocomplete**
  (Deutsch und Englisch übersetzt) mit drei Feldern:
  **Länder-Override** (Array; leer = bevorzugte Länder aus der
  Standardliste, Fallback ch/de/at), **Photon-Endpoint-URL**
  (Self-Hosting) und **Sprache der Treffer**. Kein Handanlegen an
  `data/config.php` mehr nötig. Umgesetzt über den generischen
  `adminPanel`-Mechanismus (`recordView` + Route `Admin/:page`).
- Alle `photonAddress*`-Config-Parameter sind als `level: admin`
  deklariert und tauchen damit nicht mehr in der Frontend-Config
  regulärer Benutzer auf.

### Geändert

- Der Formularkontext (Ort/Land) **gewichtet** die Treffer nur noch,
  statt zu filtern: Passende Treffer stehen oben (Ort zählt stärker
  als Land), abweichende bleiben darunter wählbar. Wer trotz
  gefülltem Ort eine Adresse anderswo sucht, bekommt sie weiterhin
  angeboten.
- Die 1.0.x-Migration (Entfernen des alten Auto-Defaults ch/de/at)
  läuft nur noch ein einziges Mal (Marker-Flag
  `photonAddressCountryCodesMigrated`). Ein über die neue
  Einstellungsseite bewusst gesetztes ch/de/at überlebt damit
  künftige Updates.

### Behoben

- Der Breitenfix aus 1.2.1 griff nicht: devbridge setzt die
  Container-Breite auch in `fixPosition()` **nach** dem
  `beforeRender`-Hook (und bei jedem window-resize) erneut fest auf
  die Feldbreite. Jetzt gewinnt eine Stylesheet-Regel mit
  `width: auto !important`; die Feldbreite bleibt als `min-width`
  erhalten.

## [1.2.1] – 2026-08-10

### Behoben

- Die Trefferliste war auf die Breite des Strassenfelds begrenzt und
  schnitt lange Labels ab. Der Dropdown-Container wächst jetzt mit dem
  Inhalt (Feldbreite als Untergrenze, Viewport als Obergrenze).

### Geändert

- Anzeigename im Manifest von «Photon Address Autocomplete (DACH)» zu
  «Photon Address Autocomplete» — die Länderauswahl ist seit 1.1.0
  konfigurierbar, der DACH-Zusatz war irreführend.

## [1.2.0] – 2026-08-10

### Neu

- Bereits ausgefüllte Subfelder (Ort, PLZ, Land) engen die Strassensuche
  ein. Das Frontend schickt sie als `city`, `zip` und `country` an den
  Endpoint; der Server hängt sie an die Photon-Query an (Volltext-Ranking)
  und verwirft zusätzlich Treffer aus dem falschen Ort bzw. Land
  (exakter, case-insensitiver Vergleich – Autofill und Photon liefern
  dieselbe Sprache). Auf die PLZ wird bewusst nicht gefiltert, da
  Strassenzüge mehrere PLZ tragen können. Liefert die eingeschränkte
  Suche nichts, wird einmal breit ohne Kontext nachgesucht. Der
  Cache-Key berücksichtigt den Kontext.
- Autocomplete auch auf der Entität `RealEstateProperty` (Feld `address`)
  der Real-Estate-Extension. Ist die Extension nicht installiert, bleibt
  die Registrierung wirkungslos.

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
