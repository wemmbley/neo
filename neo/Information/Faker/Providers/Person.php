<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Exceptions\FakerException;
use Neo\Information\Faker\Provider;

/**
 * People: names, genders, titles. Gender is "male" or "female"; null = random.
 */
final class Person extends Provider
{
    public function gender(): string
    {
        return $this->rng()->pick(['male', 'female']);
    }

    public function firstName(?string $gender = null): string
    {
        return (string) $this->pickLang('person.first_names.' . $this->resolveGender($gender));
    }

    public function firstNameMale(): string
    {
        return $this->firstName('male');
    }

    public function firstNameFemale(): string
    {
        return $this->firstName('female');
    }

    public function lastName(?string $gender = null): string
    {
        $gender = $this->resolveGender($gender);
        $list = $this->lang('person.last_names.' . $gender) ?? $this->lang('person.last_names.all');

        if (!is_array($list) || $list === []) {
            throw new FakerException('Faker locale data [person.last_names] is missing or empty.');
        }

        return (string) $this->rng()->pick($list);
    }

    /**
     * Patronymic where the locale has one (ru); otherwise a second first name.
     */
    public function middleName(?string $gender = null): string
    {
        $gender = $this->resolveGender($gender);
        $list = $this->lang('person.middle_names.' . $gender);

        return is_array($list) && $list !== []
            ? (string) $this->rng()->pick($list)
            : $this->firstName($gender);
    }

    public function prefix(?string $gender = null): string
    {
        return (string) $this->pickLang('person.prefixes.' . $this->resolveGender($gender));
    }

    public function suffix(): string
    {
        return (string) $this->pickLang('person.suffixes');
    }

    public function name(?string $gender = null): string
    {
        $gender = $this->resolveGender($gender);

        return $this->fill((string) $this->pickLang('person.name_formats'), [
            'first' => fn (): string => $this->firstName($gender),
            'last' => fn (): string => $this->lastName($gender),
            'middle' => fn (): string => $this->middleName($gender),
            'prefix' => fn (): string => $this->prefix($gender),
            'suffix' => fn (): string => $this->suffix(),
        ]);
    }

    private function resolveGender(?string $gender): string
    {
        if ($gender === null) {
            return $this->gender();
        }

        if ($gender !== 'male' && $gender !== 'female') {
            throw new FakerException("Unknown gender [{$gender}]; expected \"male\" or \"female\".");
        }

        return $gender;
    }
}
