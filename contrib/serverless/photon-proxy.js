/**
 * Photon Address Proxy (DACH) – Node.js Referenzimplementierung
 * =============================================================
 *
 * Serverless-Proxy fuer die Open-Source-Geocoding-API Photon.
 * Nur relevant, wenn der Proxy NICHT als EspoCRM-Extension, sondern
 * extern (Vercel / Netlify / AWS Lambda / Cloudflare Worker) laufen soll.
 *
 * NPM-Pakete: KEINE.
 * Node >= 18 bringt fetch, URL und AbortController nativ mit.
 * - axios / node-fetch werden nur fuer Node <= 16 gebraucht.
 * - Bei Node <= 16:  npm i node-fetch@2   und oben
 *       const fetch = require('node-fetch');
 *
 * Wichtig gegenueber der urspruenglichen Spezifikation:
 * - Der Endpoint ist https://photon.komoot.io/api/  (nicht https://komoot.io).
 * - Photon UNTERSTUETZT einen Laenderfilter: countrycode (wiederholbar).
 *   Deshalb wird serverseitig zusaetzlich gefiltert, aber nicht mehr allein
 *   darauf vertraut.
 * - Es werden mehr Treffer angefragt als ausgeliefert, weil der Filter
 *   Eintraege entfernen kann.
 */

'use strict';

const PHOTON_URL = process.env.PHOTON_URL || 'https://photon.komoot.io/api/';
const COUNTRY_CODES = (process.env.PHOTON_COUNTRIES || 'ch,de,at')
    .split(',')
    .map(c => c.trim().toLowerCase())
    .filter(c => /^[a-z]{2}$/.test(c));
const LANG = process.env.PHOTON_LANG || 'de';
const LIMIT = parseInt(process.env.PHOTON_LIMIT || '5', 10);
const TIMEOUT_MS = parseInt(process.env.PHOTON_TIMEOUT_MS || '4000', 10);
const MIN_CHARS = 3;

/**
 * CORS: NICHT auf '*' lassen, sonst ist der Proxy ein offenes Relay auf
 * Kosten der oeffentlichen Photon-Instanz. Exakte CRM-Origin eintragen.
 */
const ALLOWED_ORIGIN = process.env.ALLOWED_ORIGIN || 'https://crm.example.com';

// Laender, in denen die Hausnummer VOR der Strasse steht - im Rest gilt
// Strasse zuerst. Massgeblich ist das Land der Adresse, nicht der
// Benutzer. Identisch zur PHP-Liste in ResultMapper.php pflegen!
// Referenz: https://github.com/OpenCageData/address-formatting
const NUMBER_FIRST_COUNTRIES = [
    // anglophon
    'GB', 'IE', 'US', 'CA', 'AU', 'NZ', 'ZA', 'IN', 'PK', 'LK', 'BD',
    'SG', 'MY', 'HK', 'PH', 'KE', 'NG', 'GH', 'MT', 'JM', 'TT', 'BZ',
    'GY', 'FJ', 'PG', 'JE', 'GG', 'IM', 'GI',
    // frankophon
    'FR', 'LU', 'MC', 'HT', 'MA', 'DZ', 'TN', 'SN', 'CI', 'CM',
    // weitere mit Nummer-zuerst-Konvention
    'TH', 'VN',
];

// Laender, in denen Photons "state" nur den Landesteil traegt
// (England/Schottland/Wales) und "county" ins State-Feld gehoert.
const COUNTY_AS_STATE_COUNTRIES = ['GB', 'IE'];

const corsHeaders = {
    'Access-Control-Allow-Origin': ALLOWED_ORIGIN,
    'Access-Control-Allow-Methods': 'GET,OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type',
    'Vary': 'Origin',
    'Cache-Control': 'public, max-age=600',
    'Content-Type': 'application/json; charset=utf-8',
};

// ---------------------------------------------------------------------------
// Kernlogik (frei von Framework-Abhaengigkeiten)
// ---------------------------------------------------------------------------

function buildPhotonUrl(q, limit) {
    const url = new URL(PHOTON_URL);

    url.searchParams.set('q', q);
    url.searchParams.set('lang', LANG);
    url.searchParams.set('limit', String(limit));

    // countrycode ist wiederholbar -> append, nicht set.
    COUNTRY_CODES.forEach(code => url.searchParams.append('countrycode', code));

    return url.toString();
}

function firstNonEmpty(...values) {
    for (const value of values) {
        if (typeof value === 'string' && value.trim() !== '') {
            return value.trim();
        }

        if (typeof value === 'number') {
            return String(value);
        }
    }

    return null;
}

function buildLabel({street, zip, city, state, country}) {
    let place = [zip, city].filter(Boolean).join(' ');

    if (state && state !== city) {
        place += ` (${state})`;
    }

    if (country) {
        place = place ? `${place}, ${country}` : country;
    }

    if (street && place) {
        return `${street} – ${place}`;
    }

    return street || place || '';
}

function mapFeature(feature) {
    const p = feature && feature.properties;

    if (!p) {
        return null;
    }

    const countryCode = (p.countrycode || '').toUpperCase();

    if (!COUNTRY_CODES.includes(countryCode.toLowerCase())) {
        return null;
    }

    // Ortstreffer (Layer "city") tragen den Ortsnamen in "name" - dieser
    // darf nicht als Strasse uebernommen werden.
    const isPlace = p.osm_key === 'place' &&
        ['city', 'town', 'village', 'hamlet', 'suburb', 'state', 'country'].includes(p.osm_value);

    // Photon liefert bei Hausadressen street + housenumber; bei POIs und
    // Strassen ohne Hausnummer steht der Name in "name".
    const streetBase = isPlace ? firstNonEmpty(p.street) : firstNonEmpty(p.street, p.name);
    const houseNumber = firstNonEmpty(p.housenumber);

    const street = streetBase && houseNumber
        ? (NUMBER_FIRST_COUNTRIES.includes(countryCode)
            ? `${houseNumber} ${streetBase}`
            : `${streetBase} ${houseNumber}`)
        : streetBase;

    const city = isPlace
        ? firstNonEmpty(p.name, p.city, p.district, p.county)
        : firstNonEmpty(p.city, p.district, p.county);

    const zip = firstNonEmpty(p.postcode);

    const state = COUNTY_AS_STATE_COUNTRIES.includes(countryCode)
        ? firstNonEmpty(p.county, p.state)
        : firstNonEmpty(p.state);

    const country = firstNonEmpty(p.country);

    if (!city && !zip) {
        return null;
    }

    const coordinates = (feature.geometry && feature.geometry.coordinates) || [];

    return {
        label: buildLabel({street, zip, city, state, country}),
        street,
        zip,
        city,
        state,
        country,
        countryCode,
        lat: coordinates[1] ?? null,
        lon: coordinates[0] ?? null,
    };
}

async function searchAddresses(rawQuery, limit = LIMIT) {
    const q = (rawQuery || '').trim();

    if (q.length < MIN_CHARS) {
        return [];
    }

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), TIMEOUT_MS);

    try {
        // Ueberfetchen, damit der Filter nicht zu einer leeren Liste fuehrt.
        const response = await fetch(buildPhotonUrl(q.slice(0, 150), Math.min(limit * 3, 20)), {
            signal: controller.signal,
            headers: {
                'Accept': 'application/json',
                'User-Agent': 'espocrm-photon-proxy/1.0',
            },
        });

        if (!response.ok) {
            throw new Error(`Photon HTTP ${response.status}`);
        }

        const data = await response.json();
        const features = Array.isArray(data.features) ? data.features : [];

        const seen = new Set();
        const results = [];

        for (const feature of features) {
            const item = mapFeature(feature);

            if (!item) {
                continue;
            }

            const signature = item.label.toLowerCase();

            if (seen.has(signature)) {
                continue;
            }

            seen.add(signature);
            results.push(item);

            if (results.length >= limit) {
                break;
            }
        }

        return results;
    }
    finally {
        clearTimeout(timer);
    }
}

// ---------------------------------------------------------------------------
// Adapter 1: Vercel / Netlify Functions / Express  (req, res)
// ---------------------------------------------------------------------------

async function handler(req, res) {
    Object.entries(corsHeaders).forEach(([key, value]) => res.setHeader(key, value));

    if (req.method === 'OPTIONS') {
        res.statusCode = 204;

        return res.end();
    }

    if (req.method !== 'GET') {
        res.statusCode = 405;

        return res.end(JSON.stringify({error: 'Method not allowed'}));
    }

    try {
        const q = (req.query && req.query.q) ||
            new URL(req.url, 'http://localhost').searchParams.get('q') || '';

        const results = await searchAddresses(q);

        res.statusCode = 200;
        res.end(JSON.stringify(results));
    }
    catch (e) {
        console.error('[photon-proxy]', e);

        // Ein ausgefallener Geocoder darf das Formular nicht blockieren.
        res.statusCode = 502;
        res.end(JSON.stringify({error: 'Geocoding service unavailable'}));
    }
}

// ---------------------------------------------------------------------------
// Adapter 2: AWS Lambda / API Gateway (HTTP API v2)
// ---------------------------------------------------------------------------

async function lambdaHandler(event) {
    const method = event?.requestContext?.http?.method || event?.httpMethod || 'GET';

    if (method === 'OPTIONS') {
        return {statusCode: 204, headers: corsHeaders, body: ''};
    }

    try {
        const q = event?.queryStringParameters?.q || '';

        return {
            statusCode: 200,
            headers: corsHeaders,
            body: JSON.stringify(await searchAddresses(q)),
        };
    }
    catch (e) {
        console.error('[photon-proxy]', e);

        return {
            statusCode: 502,
            headers: corsHeaders,
            body: JSON.stringify({error: 'Geocoding service unavailable'}),
        };
    }
}

// ---------------------------------------------------------------------------
// Adapter 3: Cloudflare Worker
// ---------------------------------------------------------------------------

const worker = {
    async fetch(request) {
        if (request.method === 'OPTIONS') {
            return new Response(null, {status: 204, headers: corsHeaders});
        }

        try {
            const q = new URL(request.url).searchParams.get('q') || '';

            return new Response(JSON.stringify(await searchAddresses(q)), {
                status: 200,
                headers: corsHeaders,
            });
        }
        catch (e) {
            return new Response(JSON.stringify({error: 'Geocoding service unavailable'}), {
                status: 502,
                headers: corsHeaders,
            });
        }
    },
};

module.exports = handler;
module.exports.handler = handler;
module.exports.lambdaHandler = lambdaHandler;
module.exports.worker = worker;
module.exports.searchAddresses = searchAddresses;
module.exports.mapFeature = mapFeature;
