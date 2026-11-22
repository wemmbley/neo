<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

final class Company extends Provider
{
    public function company(): string
    {
        return $this->fill((string) $this->pickLang('company.formats'), [
            'last' => fn (): string => $this->faker->lastName('male'),
            'suffix' => fn (): string => $this->companySuffix(),
            'word' => fn (): string => (string) $this->pickLang('company.words'),
        ]);
    }

    public function companySuffix(): string
    {
        return (string) $this->pickLang('company.suffixes');
    }

    public function jobTitle(): string
    {
        return (string) $this->pickLang('company.job_titles');
    }
}
