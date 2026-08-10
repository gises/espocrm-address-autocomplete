# Photon Address Autocomplete for EspoCRM

**English** · [Deutsch](README.de.md)

Address autocomplete directly inside EspoCRM address fields, backed by the
open-source geocoding engine [Photon](https://github.com/komoot/photon)
(OpenStreetMap data).

Type in the street field, click a suggestion — street, postal code, city,
state and country are filled in together. Which countries participate is
controlled by EspoCRM's standard country list (preferred countries);
without any selection the extension defaults to Switzerland, Germany and
Austria.

**Target version: EspoCRM 10.x · PHP 8.3–8.5 · no Composer or NPM
dependencies.**

---

## Why a server-side proxy?

The frontend does **not** talk to `photon.komoot.io` directly but to a
dedicated route at `/api/v1/PhotonAddress/search`. This brings three
things:

- **No CORS.** Same-origin request within EspoCRM.
- **Authenticated.** The route is protected by the regular Espo session;
  the proxy cannot be abused as an open relay at the expense of the
  public Photon instance.
- **Server-side cache and filtering.** Autocomplete would otherwise fire
  one outbound request per typed character.

## Installation

1. Download the ZIP from the [releases](../../releases) — or build it
   yourself:

   ```bash
   ./build.sh          # -> build/photon-address-autocomplete-<version>.zip
   ```

2. In EspoCRM: **Administration → Extensions → Upload ZIP → Install**
3. **Administration → Clear Cache**
4. Verify (logged in, in the browser):

   ```
   GET https://<crm>/api/v1/PhotonAddress/search?q=Bahnhofstrasse%208001
   ```

Active on Account (billing and shipping address), Contact, Lead and —
if the Real Estate extension is installed — RealEstateProperty (field
`address`). The registration for entities that are not installed has no
effect and does no harm.

Already filled sub-fields (city, postal code, country) guide the street
search: the context location is geocoded once (cached) and passed to
Photon as a location bias (lat/lon), so matches near the entered place
rank first while streets elsewhere remain findable — deliberately typing
a street from another country still works. Results exactly matching the
entered city or country are additionally ranked to the top.

To enable further address fields —
`custom/Espo/Custom/Resources/metadata/entityDefs/<Entity>.json`:

```json
{
    "fields": {
        "myAddressField": {
            "view": "photon-address:views/fields/address-autocomplete"
        }
    }
}
```

## Configuration

### Country selection

Which countries participate in the autocomplete is controlled by the
standard list under **Administration → Address Countries**: every entry
with the **"Is Preferred"** flag is passed to Photon as a `countrycode`
filter. Order of evaluation:

1. `photonAddressCountryCodes` — manual override, supersedes the admin
   list. Editable on the settings page
   **Administration → Photon Address Autocomplete** (or in
   `data/config.php`); leave empty to use the standard list.
2. Preferred countries from **Administration → Address Countries**.
3. Fallback `['ch','de','at']` if no countries are preferred.

The settings page also exposes the Photon endpoint URL (for
self-hosting) and the result language.

The search cache includes the country list in its cache key; after
changing the preferred countries, new searches take effect immediately.

### Parameters in `data/config.php`

Optional; without an entry the defaults apply.

| Parameter | Default | Meaning |
|-----------|---------|---------|
| `photonAddressUrl` | `https://photon.komoot.io/api/` | endpoint, change for self-hosting |
| `photonAddressCountryCodes` | – (admin list) | manual country override, see above |
| `photonAddressLang` | `de` | language of the labels |
| `photonAddressLimit` | `5` | delivered results |
| `photonAddressTimeout` | `4` | seconds |
| `photonAddressCacheTtl` | `86400` | seconds, `0` disables the cache |
| `photonAddressLayers` | `[]` | e.g. `['house','street']` for address-only results |
| `photonAddressBiasZoom` | `12` | OSM zoom level of the location bias (1–18), 12 ≈ city scale |
| `photonAddressBiasScale` | `0.4` | weight of the location bias (0–1), Photon default 0.2 |

## Response format

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

`(state)` only appears in the label when it differs from the city — city
cantons and city states such as Basel-Stadt, Berlin or Vienna are not
named twice.

## Production use

`photon.komoot.io` is provided free of charge and **without an SLA**. For
permanent CRM use, a self-hosted Photon instance is the clean solution
(Docker, regional extract); afterwards it is enough to change
`photonAddressUrl`.

The data comes from OpenStreetMap and is licensed under the ODbL. A
"© OpenStreetMap contributors" notice in the form is appropriate.

Not included: per-user rate limiting. The cache dampens the load; with
many concurrent users, throttle additionally or self-host.

The scheduled job **Photon Address: Cache Cleanup** (created on install,
daily at 03:30) removes expired entries from the cache directory
`data/cache/photon-address` — the EspoCRM rebuild does not touch it.

## Project structure

```
src/                    Extension sources (becomes the ZIP 1:1)
├── manifest.json
├── files/custom/Espo/Modules/PhotonAddress/    backend
└── files/client/custom/modules/photon-address/ frontend
contrib/serverless/     Node.js variant for Vercel/Lambda/Worker
docs/REVIEW.md          technical review, verified v10 assumptions
tests/                  mapping tests against fixtures (PHP + Node)
build.sh                builds the installable package
```

## Development

```bash
bash tests/run.sh    # lint (PHP/JS/JSON) + mapping tests
./build.sh           # build the package
```

The tests run without an EspoCRM installation: `ResultMapper` deliberately
has no Espo dependencies, and the Photon response is mocked from
`tests/fixtures/features.json`. The PHP and Node implementations are
checked against the same fixtures and must produce identical results.

**Release:** bump the version in `src/manifest.json` and `CHANGELOG.md`,
then push a `vX.Y.Z` tag. The action builds, tests, checks the tag
against the manifest and attaches the ZIP to the release.

## License

[AGPL-3.0-or-later](LICENSE) — the same license as EspoCRM, since the
extension builds directly on its classes.
