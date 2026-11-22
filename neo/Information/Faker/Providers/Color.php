<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use Neo\Information\Faker\Provider;

final class Color extends Provider
{
    /** "#a1b2c3" */
    public function hexColor(): string
    {
        return sprintf('#%06x', $this->rng()->int(0, 0xFFFFFF));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public function rgbColorAsArray(): array
    {
        return [$this->rng()->int(0, 255), $this->rng()->int(0, 255), $this->rng()->int(0, 255)];
    }

    /** "12,200,45" */
    public function rgbColor(): string
    {
        return implode(',', $this->rgbColorAsArray());
    }

    /** "rgb(12,200,45)" */
    public function rgbCssColor(): string
    {
        return 'rgb(' . $this->rgbColor() . ')';
    }

    /** "hsl(210, 60%, 45%)" */
    public function hslColor(): string
    {
        return sprintf('hsl(%d, %d%%, %d%%)', $this->rng()->int(0, 359), $this->rng()->int(0, 100), $this->rng()->int(0, 100));
    }

    /** Color name in the locale language. */
    public function colorName(): string
    {
        return (string) $this->pickLang('color.names');
    }
}
