<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

/**
 * Countries, cities, streets, postcodes, coordinates.
 */
final class Address extends Provider
{
    public function country(): string
    {
        return (string) $this->rng()->pick($this->listLang('address.countries'));
    }

    /** ISO 3166-1 alpha-2 code. */
    public function countryCode(): string
    {
        return (string) $this->rng()->pickKey($this->listLang('address.countries'));
    }

    public function state(): string
    {
        return (string) $this->pickLang('address.states');
    }

    public function city(): string
    {
        return (string) $this->pickLang('address.cities');
    }

    public function streetName(): string
    {
        return $this->fill((string) $this->pickLang('address.street_formats'), [
            'base' => fn (): string => (string) $this->pickLang('address.street_bases'),
            'type' => fn (): string => (string) $this->pickLang('address.street_types'),
        ]);
    }

    public function buildingNumber(): string
    {
        return $this->numerify((string) $this->pickLang('address.building_formats'));
    }

    public function streetAddress(): string
    {
        return $this->fill((string) $this->pickLang('address.street_address_formats'), [
            'street' => fn (): string => $this->streetName(),
            'building' => fn (): string => $this->buildingNumber(),
        ]);
    }

    public function postcode(): string
    {
        return $this->numerify((string) $this->pickLang('address.postcode_formats'));
    }

    public function address(): string
    {
        return $this->fill((string) $this->pickLang('address.address_formats'), [
            'street_address' => fn (): string => $this->streetAddress(),
            'city' => fn (): string => $this->city(),
            'state' => fn (): string => $this->state(),
            'postcode' => fn (): string => $this->postcode(),
        ]);
    }

    public function latitude(float $min = -90.0, float $max = 90.0): float
    {
        return round($this->rng()->float($min, $max), 6);
    }

    public function longitude(float $min = -180.0, float $max = 180.0): float
    {
        return round($this->rng()->float($min, $max), 6);
    }

    /**
     * @return array{latitude: float, longitude: float}
     */
    public function coordinates(): array
    {
        return ['latitude' => $this->latitude(), 'longitude' => $this->longitude()];
    }
}
