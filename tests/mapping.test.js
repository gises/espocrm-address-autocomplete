/**
 * Testet die Node-Referenzimplementierung (contrib/serverless/photon-proxy.js)
 * gegen dieselben Fixtures wie der PHP-Mapper. Beide muessen identische
 * Ergebnisse liefern.
 *
 * Ausfuehren:  node tests/mapping.test.js
 */

'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const features = JSON.parse(
    fs.readFileSync(path.join(__dirname, 'fixtures', 'features.json'), 'utf8')
);

let capturedUrl = null;

// Photon wird nicht real angefragt - der Test prueft URL-Aufbau und Mapping.
global.fetch = async (url) => {
    capturedUrl = url;

    return {
        ok: true,
        status: 200,
        json: async () => ({type: 'FeatureCollection', features}),
    };
};

const {searchAddresses} = require('../contrib/serverless/photon-proxy.js');

(async () => {
    const results = await searchAddresses('Bahnhofstrasse 8');

    console.log('URL:', capturedUrl);
    console.log(JSON.stringify(results, null, 2));

    assert.ok(capturedUrl.startsWith('https://photon.komoot.io/api/?'), 'korrekter Endpoint');
    assert.ok(
        capturedUrl.includes('countrycode=ch&countrycode=de&countrycode=at'),
        'countrycode ist wiederholbar, nicht kommasepariert'
    );
    assert.ok(capturedUrl.includes('lang=de'), 'lang=de');
    assert.ok(capturedUrl.includes('limit=15'), 'Overfetch vor dem Filtern');

    assert.strictEqual(results.length, 3, 'FR-Treffer und unbrauchbarer Eintrag gefiltert');

    assert.strictEqual(results[0].label, 'Bahnhofstrasse 8 – 8001 Zürich, Schweiz');
    assert.strictEqual(results[0].street, 'Bahnhofstrasse 8');
    assert.strictEqual(results[0].zip, '8001');
    assert.strictEqual(results[0].city, 'Zürich');
    assert.strictEqual(results[0].state, 'Zürich');
    assert.strictEqual(results[0].country, 'Schweiz');
    assert.strictEqual(results[0].countryCode, 'CH');

    assert.strictEqual(results[1].city, 'Berlin', 'city aus name bei osm_value=city');
    assert.strictEqual(results[1].street, null, 'Ortstreffer setzt keine Strasse');
    assert.strictEqual(results[1].label, '10117 Berlin, Deutschland');

    assert.strictEqual(results[2].street, 'Kärntner Straße', 'name als Fallback fuer street');
    assert.strictEqual(results[2].label, 'Kärntner Straße – 1010 Wien, Österreich');

    assert.deepStrictEqual(await searchAddresses('ab'), [], 'Abbruch unter 3 Zeichen');

    console.log('\nNode-Referenz: alle Assertions bestanden.');
})();
