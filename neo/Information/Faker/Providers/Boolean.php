<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

final class Boolean extends Provider
{
    /**
     * @param int $chanceOfGettingTrue 0-100
     */
    public function boolean(int $chanceOfGettingTrue = 50): bool
    {
        return $this->rng()->int(1, 100) <= max(0, min(100, $chanceOfGettingTrue));
    }
}
