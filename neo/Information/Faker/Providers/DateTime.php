<?php

declare(strict_types=1);

namespace Neo\Information\Faker\Providers;

use DateTimeInterface;
use DateTimeZone;
use Neo\Information\Datetime\Chronos;
use Neo\Information\Faker\Provider;

/**
 * Dates and times as {@see Chronos} instances (or formatted strings).
 *
 * Boundaries are a timestamp, a DateTimeInterface or any string Chronos::parse() understands
 * ("now", "-30 years", "2020-01-01").
 */
final class DateTime extends Provider
{
    /** Random moment between the Unix epoch and $max. */
    public function dateTime(DateTimeInterface|int|string $max = 'now', ?string $timezone = null): Chronos
    {
        return $this->make($this->rng()->int(0, $this->timestamp($max)), $timezone);
    }

    public function dateTimeBetween(
        DateTimeInterface|int|string $start = '-30 years',
        DateTimeInterface|int|string $end = 'now',
        ?string $timezone = null,
    ): Chronos {
        return $this->make($this->rng()->int($this->timestamp($start), $this->timestamp($end)), $timezone);
    }

    public function dateTimeThisYear(DateTimeInterface|int|string $max = 'now', ?string $timezone = null): Chronos
    {
        return $this->dateTimeBetween(Chronos::now()->startOfYear(), $max, $timezone);
    }

    public function dateTimeThisMonth(DateTimeInterface|int|string $max = 'now', ?string $timezone = null): Chronos
    {
        return $this->dateTimeBetween(Chronos::now()->startOfMonth(), $max, $timezone);
    }

    public function date(string $format = 'Y-m-d', DateTimeInterface|int|string $max = 'now'): string
    {
        return $this->dateTime($max)->format($format);
    }

    public function time(string $format = 'H:i:s'): string
    {
        return gmdate($format, $this->rng()->int(0, 86399));
    }

    public function unixTime(DateTimeInterface|int|string $max = 'now'): int
    {
        return $this->rng()->int(0, $this->timestamp($max));
    }

    public function iso8601(DateTimeInterface|int|string $max = 'now'): string
    {
        return $this->dateTime($max)->format('c');
    }

    public function year(DateTimeInterface|int|string $max = 'now'): string
    {
        return $this->date('Y', $max);
    }

    /** Two-digit month, "01" - "12". */
    public function month(): string
    {
        return sprintf('%02d', $this->rng()->int(1, 12));
    }

    /** Two-digit day of month, "01" - "28" (valid in every month). */
    public function dayOfMonth(): string
    {
        return sprintf('%02d', $this->rng()->int(1, 28));
    }

    public function monthName(): string
    {
        return (string) $this->pickLang('datetime.months');
    }

    public function dayOfWeek(): string
    {
        return (string) $this->pickLang('datetime.days');
    }

    public function timezone(): string
    {
        return (string) $this->rng()->pick(DateTimeZone::listIdentifiers());
    }

    private function timestamp(DateTimeInterface|int|string $value): int
    {
        return match (true) {
            is_int($value) => $value,
            $value instanceof DateTimeInterface => $value->getTimestamp(),
            default => Chronos::parse($value)->getTimestamp(),
        };
    }

    private function make(int $timestamp, ?string $timezone): Chronos
    {
        $date = Chronos::createFromTimestamp($timestamp);

        return $timezone === null ? $date : $date->setTimezone($timezone);
    }
}
