# Photon Address Autocomplete für EspoCRM

[English](README.md) · **Deutsch**

Adress-Autocomplete direkt im Adressfeld von EspoCRM, auf Basis der
Open-Source-Geocoding-Engine [Photon](https://github.com/komoot/photon)
(OpenStreetMap-Daten).

Tippen im Straßenfeld, Treffer anklicken – Straße, PLZ, Ort,
Kanton/Bundesland und Land werden gemeinsam gesetzt. Welche Länder
teilnehmen, steuert die Standardländerliste von EspoCRM (bevorzugte
Länder); ohne Auswahl gilt der Default Schweiz, Deutschland, Österreich.

**Zielversion: EspoCRM 10.x · PHP 8.3–8.5 · keine Composer- oder
NPM-Abhängigkeiten.**

---

## Warum ein eigener Proxy?

Das Frontend spricht **nicht** direkt mit `photon.komoot.io`, sondern mit
einer eigenen Route unter `/api/v1/PhotonAddress/search`. Das bringt drei
Dinge mit:

- **Kein CORS.** Same-Origin-Request innerhalb von EspoCRM.
- **Authentifiziert.** Die Route hängt an der regulären Espo-Session; der
  Proxy lässt sich nicht als offenes Relay auf Kosten der öffentlichen
  Photon-Instanz missbrauchen.
- **Cache und Filter serverseitig.** Autocomplete erzeugt sonst pro
  getipptem Zeichen einen Request nach außen.

## Installation

1. ZIP aus den [Releases](../../releases) laden – oder selbst bauen:

   ```bash
   ./build.sh          # -> build/photon-address-autocomplete-<version>.zip
   ```

2. In EspoCRM: **Administration → Erweiterungen → ZIP hochladen → Installieren**
3. **Administration → Cache leeren**
4. Prüfen (eingeloggt im Browser):

   ```
   GET https://<crm>/api/v1/PhotonAddress/search?q=Bahnhofstrasse%208001
   ```

Aktiv auf Account (Rechnungs- und Lieferadresse), Contact, Lead sowie –
falls die Real-Estate-Extension installiert ist – RealEstateProperty
(Feld `address`). Die Registrierung für nicht installierte Entitäten ist
wirkungslos und stört nicht.

Bereits ausgefüllte Subfelder (Ort, PLZ, Land) lenken die Strassensuche:
Der Kontext-Ort wird einmalig geokodiert (gecacht) und als Location-Bias
(lat/lon) an Photon übergeben — Treffer nahe dem eingegebenen Ort stehen
oben, Strassen anderswo bleiben findbar; auch das bewusste Eintippen
einer Strasse aus einem anderen Land funktioniert. Treffer, die exakt
zum eingegebenen Ort bzw. Land passen, werden zusätzlich nach oben
sortiert.

Weitere Adressfelder aktivieren – `custom/Espo/Custom/Resources/metadata/entityDefs/<Entity>.json`:

```json
{
    "fields": {
        "meinAdressFeld": {
            "view": "photon-address:views/fields/address-autocomplete"
        }
    }
}
```

## Konfiguration

### Länderauswahl

Welche Länder am Autocomplete teilnehmen, steuert die Standardliste unter
**Administration → Adresse Länder**: alle Einträge mit dem Flag
**«Wird bevorzugt»** werden als `countrycode`-Filter an Photon übergeben.
Reihenfolge der Auswertung:

1. `photonAddressCountryCodes` — manueller Override, übersteuert die
   Adminliste. Editierbar auf der Einstellungsseite
   **Administration → Photon Address Autocomplete** (oder in
   `data/config.php`); leer lassen, um die Standardliste zu verwenden.
2. Bevorzugte Länder aus **Administration → Adresse Länder**.
3. Fallback `['ch','de','at']`, falls keine Länder bevorzugt sind.

Die Einstellungsseite bietet zusätzlich die Photon-Endpoint-URL
(Self-Hosting) und die Sprache der Treffer an.

Der Suchcache berücksichtigt die Länderliste im Cache-Key; nach einer
Änderung der bevorzugten Länder greifen neue Suchen sofort.

### Parameter in `data/config.php`

Optional; ohne Eintrag gelten die Defaults.

| Parameter | Default | Bedeutung |
|-----------|---------|-----------|
| `photonAddressUrl` | `https://photon.komoot.io/api/` | Endpoint, für Self-Hosting ändern |
| `photonAddressCountryCodes` | – (Adminliste) | manueller Länder-Override, s. o. |
| `photonAddressLang` | `de` | Sprache der Bezeichnungen |
| `photonAddressLimit` | `5` | ausgelieferte Treffer |
| `photonAddressTimeout` | `4` | Sekunden |
| `photonAddressCacheTtl` | `86400` | Sekunden, `0` schaltet den Cache ab |
| `photonAddressLayers` | `[]` | z. B. `['house','street']` für reine Adresstreffer |
| `photonAddressBiasZoom` | `12` | OSM-Zoomstufe des Orts-Bias (1–18), 12 ≈ Stadt-Massstab |
| `photonAddressBiasScale` | `0.4` | Gewicht des Orts-Bias (0–1), Photon-Default 0.2 |

## Antwortformat

```json
[
  {
    "label": "Bahnhofstrasse 8 – 8001 Zürich, Schweiz",
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

`(Kanton/Bundesland)` erscheint im Label nur, wenn es sich vom Ort
unterscheidet – Stadtkantone und Stadtstaaten wie Basel-Stadt, Berlin oder
Wien werden also nicht doppelt genannt.

## Produktivbetrieb

`photon.komoot.io` wird kostenlos und **ohne SLA** bereitgestellt. Für
dauerhaften CRM-Einsatz ist eine eigene Photon-Instanz die saubere Lösung
(Docker, regionaler Extrakt); danach genügt es, `photonAddressUrl`
umzustellen.

Die Daten stammen aus OpenStreetMap und stehen unter der ODbL. Ein Hinweis
„© OpenStreetMap-Mitwirkende" im Formular ist angebracht.

Nicht enthalten: Rate Limiting pro Nutzer. Der Cache dämpft die Last; bei
vielen gleichzeitigen Nutzern zusätzlich drosseln oder self-hosten. Der
Cache-Ordner `data/cache/photon-address` wächst und wird vom
EspoCRM-Rebuild nicht geleert.

## Projektstruktur

```
src/                    Extension-Quellen (wird 1:1 zur ZIP)
├── manifest.json
├── files/custom/Espo/Modules/PhotonAddress/    Backend
└── files/client/custom/modules/photon-address/ Frontend
contrib/serverless/     Node.js-Variante für Vercel/Lambda/Worker
docs/REVIEW.md          Technisches Review, verifizierte v10-Annahmen
tests/                  Mapping-Tests gegen Fixtures (PHP + Node)
build.sh                baut das installierbare Paket
```

## Entwicklung

```bash
bash tests/run.sh    # Lint (PHP/JS/JSON) + Mapping-Tests
./build.sh           # Paket bauen
```

Die Tests laufen ohne EspoCRM-Installation: `ResultMapper` hat bewusst keine
Espo-Abhängigkeiten, und die Photon-Antwort wird aus
`tests/fixtures/features.json` gemockt. PHP- und Node-Implementierung werden
gegen dieselben Fixtures geprüft und müssen identische Ergebnisse liefern.

**Release:** Version in `src/manifest.json` und `CHANGELOG.md` anheben, dann
einen Tag `vX.Y.Z` pushen. Die Action baut, testet, prüft Tag gegen Manifest
und hängt die ZIP ans Release.

## Lizenz

[AGPL-3.0-or-later](LICENSE) – dieselbe Lizenz wie EspoCRM, da die Extension
direkt auf dessen Klassen aufsetzt.
