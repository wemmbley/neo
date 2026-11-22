<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

final class Phone extends Provider
{
    /** Formatted for the locale, e.g. "+7 (912) 345-67-89". */
    public function phoneNumber(): string
    {
        return $this->numerify((string) $this->pickLang('phone.formats'));
    }

    /** Digits only with the country code, e.g. "+79123456789". */
    public function e164PhoneNumber(): string
    {
        return $this->numerify((string) $this->pickLang('phone.e164_formats'));
    }
}
