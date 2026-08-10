<?php

declare(strict_types=1);

namespace Espo\Modules\PhotonAddress\Tools\Photon;

/**
 * Bildet ein Photon-GeoJSON-Feature auf ein flaches Objekt ab, das die
 * EspoCRM-Address-Felder direkt befuellen kann.
 */
class ResultMapper
{
    /**
     * @param array<string, mixed> $feature
     * @param string[] $allowedCountryCodes Kleingeschrieben.
     * @return array<string, mixed>|null Null, wenn das Feature verworfen wird.
     */
    public function map(array $feature, array $allowedCountryCodes): ?array
    {
        $properties = $feature['properties'] ?? null;

        if (!is_array($properties)) {
            return null;
        }

        $countryCode = strtoupper(trim((string) ($properties['countrycode'] ?? '')));

        // Anforderung 4: DACH-Filter. Der Parameter countrycode wird bereits
        // an Photon uebergeben; diese Pruefung ist die zweite Verteidigungslinie
        // fuer den Fall, dass eine (aeltere) Photon-Instanz den Parameter
        // ignoriert.
        $allowedUpper = array_map('strtoupper', $allowedCountryCodes);

        if ($countryCode === '' || !in_array($countryCode, $allowedUpper, true)) {
            return null;
        }

        $street = $this->buildStreet($properties);
        $zip = $this->stringOrNull($properties['postcode'] ?? null);
        $city = $this->buildCity($properties);
        $state = $this->stringOrNull($properties['state'] ?? null);
        $country = $this->stringOrNull($properties['country'] ?? null);

        // Ohne Ort ist der Eintrag fuer ein Adressformular wertlos.
        if ($city === null && $zip === null) {
            return null;
        }

        $coordinates = $feature['geometry']['coordinates'] ?? null;

        return [
            'label' => $this->buildLabel($street, $zip, $city, $state, $country),
            'street' => $street,
            'zip' => $zip,
            'city' => $city,
            'state' => $state,
            'country' => $country,
            'countryCode' => $countryCode,
            'lat' => is_array($coordinates) && isset($coordinates[1]) ? (float) $coordinates[1] : null,
            'lon' => is_array($coordinates) && isset($coordinates[0]) ? (float) $coordinates[0] : null,
            'osmId' => isset($properties['osm_id']) ? (string) $properties['osm_id'] : null,
        ];
    }

    /**
     * Photon liefert bei Hausadressen "street" + "housenumber"; bei POIs
     * und Strassen ohne Hausnummer steht der Name dagegen in "name".
     * Deshalb "street" mit Fallback auf "name" - nicht umgekehrt.
     *
     * @param array<string, mixed> $properties
     */
    private function buildStreet(array $properties): ?string
    {
        // Ortstreffer tragen den Ortsnamen in "name" - der darf nicht als
        // Strasse ins Formular wandern.
        $street = $this->isPlaceResult($properties)
            ? $this->stringOrNull($properties['street'] ?? null)
            : $this->stringOrNull($properties['street'] ?? null)
                ?? $this->stringOrNull($properties['name'] ?? null);

        if ($street === null) {
            return null;
        }

        $houseNumber = $this->stringOrNull($properties['housenumber'] ?? null);

        return $houseNumber !== null ? "{$street} {$houseNumber}" : $street;
    }

    /**
     * Bei Treffern des Layers "city" steht der Ortsname in "name",
     * bei Adressen in "city". Zusaetzlich Fallbacks fuer Stadtteile.
     *
     * @param array<string, mixed> $properties
     */
    private function buildCity(array $properties): ?string
    {
        if ($this->isPlaceResult($properties)) {
            $fromName = $this->stringOrNull($properties['name'] ?? null);

            if ($fromName !== null) {
                return $fromName;
            }
        }

        return $this->stringOrNull($properties['city'] ?? null)
            ?? $this->stringOrNull($properties['district'] ?? null)
            ?? $this->stringOrNull($properties['county'] ?? null);
    }

    /**
     * Treffer vom Typ Ort/Region statt Adresse.
     *
     * @param array<string, mixed> $properties
     */
    private function isPlaceResult(array $properties): bool
    {
        $osmKey = (string) ($properties['osm_key'] ?? '');
        $osmValue = (string) ($properties['osm_value'] ?? '');

        return $osmKey === 'place' && in_array(
            $osmValue,
            ['city', 'town', 'village', 'hamlet', 'suburb', 'state', 'country'],
            true
        );
    }

    /**
     * Format: "Strasse Hausnummer - PLZ Ort (Kanton/Bundesland), Land"
     */
    private function buildLabel(
        ?string $street,
        ?string $zip,
        ?string $city,
        ?string $state,
        ?string $country
    ): string {
        $place = trim(implode(' ', array_filter([$zip, $city], fn($v) => $v !== null && $v !== '')));

        if ($state !== null && $state !== '' && $state !== $city) {
            $place .= " ({$state})";
        }

        if ($country !== null && $country !== '') {
            $place = $place !== '' ? "{$place}, {$country}" : $country;
        }

        if ($street !== null && $street !== '' && $place !== '') {
            return "{$street} – {$place}";
        }

        return $street ?? $place;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
