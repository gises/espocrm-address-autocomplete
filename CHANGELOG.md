# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer](https://semver.org/lang/de/).

## [1.6.0] – 2026-08-12

### Neu

Community-Feedback (UK): OSM kennt viele britische Hausnummern nicht
(Royal Mails PAF ist proprietär und darf nicht importiert werden) —
Photon liefert dann nur Strassen-Treffer.

- Getippte Hausnummern überleben jetzt die Übernahme: Wählt man bei
  eingegebenem «79 Wester Broom Drive» den Strassen-Treffer «Wester
  Broom Drive» (ohne Hausnummer), wird die 79 nicht mehr verworfen,
  sondern nach der Konvention des Treffer-Landes platziert. Erkannt
  werden Formen wie «79», «12a», «76-3», «29/2» am Anfang oder Ende
  des Suchbegriffs; steht das Token bereits im Strassennamen
  («Route 66»), passiert nichts. Treffer mit eigener Hausnummer
  bleiben unberührt.
- Endpoint-Antwort um `houseNumber` (Roh-Hausnummer, nullable) und
  `numberFirst` (bool) erweitert; PHP- und Node-Mapper identisch.

## [1.5.2] – 2026-08-12

### Geändert

- Die Liste der Länder mit Hausnummer-zuerst-Konvention ist von 9 auf
  40 Einträge erweitert (anglophone und frankophone Posttradition
  sowie TH/VN). Massgeblich ist immer das Land der gefundenen
  Adresse, nicht der Benutzer. Referenz für Zweifelsfälle:
  OpenCage address-formatting.

## [1.5.1] – 2026-08-12

### Behoben

Community-Feedback aus dem EspoCRM-Forum (Test mit UK-Adressen):

- Die Hausnummer steht jetzt länderabhängig vor oder nach der Strasse:
  in GB, IE, FR, US, CA, AU, NZ, ZA und LU vorne
  («29/2 Hardengreen Industrial Estate»), sonst wie bisher hinten
  («Bahnhofstrasse 8»).
- In GB und IE wird das State-Feld aus Photons `county` befüllt
  (z. B. «Midlothian») statt aus `state`, das dort nur den Landesteil
  trägt (England/Schottland/Wales). Fallback auf `state`, wenn kein
  County geliefert wird.
- Beide Regeln identisch in PHP- und Node-Mapper umgesetzt; die
  gemeinsamen Fixtures decken den UK-Fall jetzt ab.

## [1.5.0] – 2026-08-10

### Neu

- Scheduled Job **«Photon Address: Cache-Bereinigung»** (täglich 03:30,
  DE/EN übersetzt): löscht Einträge aus `data/cache/photon-address`,
  die älter als die konfigurierte Cache-TTL sind; bei TTL 0
  (Cache deaktiviert) werden alle Restdateien entfernt. `AfterInstall`
  legt den Job-Datensatz an, falls er fehlt (Espos Populator für
  `app/scheduledJobs.json` läuft nur über die Konsole); das neue
  `AfterUninstall`-Skript räumt ihn bei einer Deinstallation wieder
  weg. Damit ist der letzte bekannte Betriebspunkt geschlossen —
  der Cache-Ordner wächst nicht mehr unbegrenzt.

## [1.4.0] – 2026-08-10

### Neu

- Der Location-Bias ist konfigurierbar: **Orts-Bias: Zoomstufe**
  (`photonAddressBiasZoom`, 1–18, Default 12 ≈ Stadt-Massstab) und
  **Orts-Bias: Gewicht** (`photonAddressBiasScale`, 0–1, Default 0.4,
  0 schaltet den Bias ab; Photons eigener Default wäre 0.2). Beide
  Felder stehen auf der Einstellungsseite
  **Administration → Photon Address Autocomplete** (DE/EN übersetzt),
  `AfterInstall` belegt die Defaults vor, und der Such-Cache-Key
  berücksichtigt beide Werte — Änderungen greifen sofort.

## [1.3.2] – 2026-08-10

### Behoben

- Alle Suchen fielen mit «Photon returned HTTP 301» aus, wenn die
  Endpoint-URL als `http://…` konfiguriert war — Espos URL-Feld ergänzt
  bei Eingabe ohne Schema selbst `http://`, und `photon.komoot.io`
  leitet HTTP per 301 auf HTTPS um. Der Client folgt Redirects jetzt
  (max. 3, nur http/https).

## [1.3.1] – 2026-08-10

### Behoben

- Der Formularkontext wurde in den Photon-Suchtext gemischt; das
  Volltext-Matching liess die Ortsangabe dominieren: «Grubstrasse 14»
  mit Kontext Mainz lieferte beliebige «…straße 14»-Treffer in Mainz,
  und bewusst eingegebene Strassen aus anderen Ländern erschienen erst
  nach weiteren Zeichen. Der Kontext-Ort wird jetzt einmalig geokodiert
  (gecacht, auch negativ) und als Photon-Location-Bias übergeben
  (lat/lon, zoom 12, location_bias_scale 0.4) — die Suche läuft wieder
  mit dem reinen Strassentext, Photon gewichtet selbst nach Nähe,
  nichts wird ausgeschlossen. Das exakte Ort/Land-Ranking aus 1.3.0
  bleibt zusätzlich bestehen.

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
