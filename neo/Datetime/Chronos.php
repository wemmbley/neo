<?php

declare(strict_types=1);

namespace Neo\Datetime;

use BadMethodCallException;
use Closure;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use JsonSerializable;
use LogicException;
use Stringable;
use Throwable;

/**
 * Chronos — иммутабельный аналог Carbon в одном файле 
 * (PHP 8.2+, без зависимостей).
 *
 * Каждая операция возвращает НОВЫЙ объект; оригинал не меняется.
 *
 * ЯДРО
 *   создание ........ now, today, tomorrow, yesterday, parse, tryParse, 
 *                     create, createFromDate, createFromTime, createFromFormat, 
 *                     createFromTimestamp(Ms), instance
 *   геттеры ......... $c->year, ->month, ->day, ->hour, ->dayOfWeek, 
 *                     ->dayOfYear, ->weekOfYear, ->quarter, ->daysInMonth, 
 *                     ->timestamp, ->offset, ->age ... (см. __get)
 *   арифметика ...... addDays/subDays/addMonths/addMonthsNoOverflow/addWeekdays/... + add()/sub()
 *   границы ......... startOfDay/endOfWeek/startOfMonth/... и startOf('unit') / endOf('unit')
 *   сравнение ....... eq, ne, gt, gte, lt, lte, between, min, max, closest, isSameDay, isPast, ...
 *   разница ......... diffInSeconds/Minutes/Hours/Days/Weeks/Months/Years/Weekdays, diff()
 *   форматирование .. format, toDateTimeString, toIso8601String, toRfc7231String, jsonSerialize ...
 *   таймзоны ........ setTimezone (тот же момент), shiftTimezone (то же «настенное» время), utc
 *   test-now ........ setTestNow / withTestNow — заморозка «сейчас» для тестов
 *
 * ВТОРОСТЕПЕННОЕ
 *   diffForHumans (en, ru, uk), translatedFormat, dayName/monthName, registerLocale,
 *   макросы, rangeTo(), календарные помощники (nthOfMonth, next, previous ...), будни.
 *
 * Заметки:
 *  - addMonths()/addYears() ведут себя как в PHP/Carbon (31 янв + 1 мес = 3 марта);
 *    для «обрезки» до конца месяца есть addMonthsNoOverflow()/addYearsNoOverflow().
 *  - diffInX() по умолчанию возвращает модуль (как Carbon 2); $absolute=false даёт знак (other - this).
 *  - createFromFormat() строгий: 2024-02-31 бросит ChronosException. Для детерминизма без test-now
 *    используйте '!' или '|' в формате (иначе недостающие поля берутся из реальных часов PHP).
 *  - Строки с абсолютной датой (2026-12-25) парсятся как в PHP; относительные ('tomorrow', '+1 week',
 *    'next friday', '10:30', 'Dec 25') привязываются к test-now, если он задан.
 *
 * @property-read int           $year
 * @property-read int           $month
 * @property-read int           $day
 * @property-read int           $hour
 * @property-read int           $minute
 * @property-read int           $second
 * @property-read int           $micro
 * @property-read int           $microsecond
 * @property-read int           $dayOfWeek       0 (вс) .. 6 (сб)
 * @property-read int           $dayOfWeekIso    1 (пн) .. 7 (вс)
 * @property-read int           $dayOfYear       1 .. 366
 * @property-read int           $weekOfYear      ISO-неделя
 * @property-read int           $isoWeekYear
 * @property-read int           $weekOfMonth
 * @property-read int           $weeksInYear
 * @property-read int           $daysInMonth
 * @property-read int           $daysInYear
 * @property-read int           $quarter
 * @property-read int           $timestamp
 * @property-read DateTimeZone  $timezone
 * @property-read DateTimeZone  $tz
 * @property-read string        $timezoneName
 * @property-read string        $tzName
 * @property-read int           $offset          секунды от UTC
 * @property-read int           $offsetMinutes
 * @property-read float         $offsetHours
 * @property-read int           $age             полных лет от этой даты до «сейчас» (со знаком)
 * @property-read string        $dayName
 * @property-read string        $shortDayName
 * @property-read string        $monthName
 * @property-read string        $shortMonthName
 * @property-read string        $locale
 *
 * @method static addYear(int $n = 1)
 * @method static addMonth(int $n = 1)
 * @method static addWeek(int $n = 1)
 * @method static addDay(int $n = 1)
 * @method static addWeekday(int $n = 1)
 * @method static addHour(int $n = 1)
 * @method static addMinute(int $n = 1)
 * @method static addSecond(int $n = 1)
 * @method static subYear(int $n = 1)
 * @method static subMonth(int $n = 1)
 * @method static subWeek(int $n = 1)
 * @method static subDay(int $n = 1)
 * @method static subWeekday(int $n = 1)
 * @method static subHour(int $n = 1)
 * @method static subMinute(int $n = 1)
 * @method static subSecond(int $n = 1)
 * @method bool isMonday()
 * @method bool isTuesday()
 * @method bool isWednesday()
 * @method bool isThursday()
 * @method bool isFriday()
 * @method bool isSaturday()
 * @method bool isSunday()
 */
class Chronos implements JsonSerializable, Stringable
{
    public const SUNDAY = 0;
    public const MONDAY = 1;
    public const TUESDAY = 2;
    public const WEDNESDAY = 3;
    public const THURSDAY = 4;
    public const FRIDAY = 5;
    public const SATURDAY = 6;

    private const DAY_KEYS = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];

    private const LOCALES = [
        'en' => [
            'plural' => 'germanic',
            'units' => [
                'year' => ['year', 'years'],
                'month' => ['month', 'months'],
                'week' => ['week', 'weeks'],
                'day' => ['day', 'days'],
                'hour' => ['hour', 'hours'],
                'minute' => ['minute', 'minutes'],
                'second' => ['second', 'seconds'],
            ],
            'unit_format' => ':count :unit',
            'ago' => ':time ago',
            'from_now' => ':time from now',
            'before' => ':time before',
            'after' => ':time after',
            'just_now' => 'just now',
            'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'months_genitive' => null,
            'months_short' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'days_short' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        ],
        'ru' => [
            'plural' => 'slavic',
            'units' => [
                'year' => ['год', 'года', 'лет'],
                'month' => ['месяц', 'месяца', 'месяцев'],
                'week' => ['неделя', 'недели', 'недель'],
                'day' => ['день', 'дня', 'дней'],
                'hour' => ['час', 'часа', 'часов'],
                'minute' => ['минута', 'минуты', 'минут'],
                'second' => ['секунда', 'секунды', 'секунд'],
            ],
            'unit_format' => ':count :unit',
            'ago' => ':time назад',
            'from_now' => 'через :time',
            'before' => ':time до',
            'after' => ':time после',
            'just_now' => 'только что',
            'months' => ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'],
            'months_genitive' => ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'],
            'months_short' => ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'],
            'days' => ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'],
            'days_short' => ['вс', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'],
        ],
        'uk' => [
            'plural' => 'slavic',
            'units' => [
                'year' => ['рік', 'роки', 'років'],
                'month' => ['місяць', 'місяці', 'місяців'],
                'week' => ['тиждень', 'тижні', 'тижнів'],
                'day' => ['день', 'дні', 'днів'],
                'hour' => ['година', 'години', 'годин'],
                'minute' => ['хвилина', 'хвилини', 'хвилин'],
                'second' => ['секунда', 'секунди', 'секунд'],
            ],
            'unit_format' => ':count :unit',
            'ago' => ':time тому',
            'from_now' => 'через :time',
            'before' => ':time до',
            'after' => ':time після',
            'just_now' => 'щойно',
            'months' => ['січень', 'лютий', 'березень', 'квітень', 'травень', 'червень', 'липень', 'серпень', 'вересень', 'жовтень', 'листопад', 'грудень'],
            'months_genitive' => ['січня', 'лютого', 'березня', 'квітня', 'травня', 'червня', 'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня'],
            'months_short' => ['січ', 'лют', 'бер', 'кві', 'тра', 'чер', 'лип', 'сер', 'вер', 'жов', 'лис', 'гру'],
            'days' => ['неділя', 'понеділок', 'вівторок', 'середа', 'четвер', 'пʼятниця', 'субота'],
            'days_short' => ['нд', 'пн', 'вт', 'ср', 'чт', 'пт', 'сб'],
        ],
    ];

    private DateTimeImmutable $dt;
    private ?string $localeCode = null;

    private static ?self $testNow = null;
    private static string $defaultLocale = 'en';
    private static int $weekStartsAt = self::MONDAY;
    /** @var list<int> */
    private static array $weekendDays = [self::SATURDAY, self::SUNDAY];
    /** @var array<string, callable> */
    private static array $macros = [];
    /** @var array<string, array<string, mixed>> */
    private static array $customLocales = [];

    // =====================================================================
    //  ЯДРО: создание
    // =====================================================================

    public function __construct(
        self|DateTimeInterface|string|null $time = null,
        DateTimeZone|string|null $timezone = null,
    ) {
        $tz = self::toTimezone($timezone);

        if ($time instanceof self) {
            $dt = $time->dt;
            $this->localeCode = $time->localeCode;
        } elseif ($time instanceof DateTimeInterface) {
            $dt = DateTimeImmutable::createFromInterface($time);
        } else {
            $this->dt = self::parseToImmutable($time, $tz);

            return;
        }

        $this->dt = $tz !== null ? $dt->setTimezone($tz) : $dt;
    }

    public static function now(DateTimeZone|string|null $tz = null): static
    {
        return new static(null, $tz);
    }

    public static function today(DateTimeZone|string|null $tz = null): static
    {
        return static::now($tz)->startOfDay();
    }

    public static function tomorrow(DateTimeZone|string|null $tz = null): static
    {
        return static::today($tz)->addDays(1);
    }

    public static function yesterday(DateTimeZone|string|null $tz = null): static
    {
        return static::today($tz)->subDays(1);
    }

    public static function parse(self|DateTimeInterface|string|null $time = null, DateTimeZone|string|null $tz = null): static
    {
        return new static($time, $tz);
    }

    public static function tryParse(self|DateTimeInterface|string|null $time = null, DateTimeZone|string|null $tz = null): ?static
    {
        try {
            return new static($time, $tz);
        } catch (ChronosException) {
            return null;
        }
    }

    public static function isValid(string $time): bool
    {
        return static::tryParse($time) !== null;
    }

    public static function instance(DateTimeInterface $date): static
    {
        return new static($date);
    }

    /**
     * Строгая сборка из компонентов. Год null — текущий. Невалидные значения → ChronosException.
     */
    public static function create(
        ?int $year = null,
        int $month = 1,
        int $day = 1,
        int $hour = 0,
        int $minute = 0,
        int $second = 0,
        int $microsecond = 0,
        DateTimeZone|string|null $tz = null,
    ): static {
        $zone = self::toTimezone($tz) ?? self::defaultTimezone();
        $year ??= (int) static::now($zone)->format('Y');

        if (!checkdate($month, $day, $year)) {
            throw new ChronosException(sprintf('Invalid date: %d-%d-%d.', $year, $month, $day));
        }
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new ChronosException(sprintf('Invalid time: %d:%d:%d.', $hour, $minute, $second));
        }
        if ($microsecond < 0 || $microsecond > 999_999) {
            throw new ChronosException("Invalid microsecond: $microsecond.");
        }

        $dt = (new DateTimeImmutable('@0'))
            ->setTimezone($zone)
            ->setDate($year, $month, $day)
            ->setTime($hour, $minute, $second, $microsecond);

        return new static($dt);
    }

    /** Дата из компонентов, время — текущее. */
    public static function createFromDate(int $year, int $month, int $day, DateTimeZone|string|null $tz = null): static
    {
        if (!checkdate($month, $day, $year)) {
            throw new ChronosException(sprintf('Invalid date: %d-%d-%d.', $year, $month, $day));
        }

        return static::now($tz)->setDate($year, $month, $day);
    }

    /** Время из компонентов, дата — текущая. */
    public static function createFromTime(int $hour = 0, int $minute = 0, int $second = 0, DateTimeZone|string|null $tz = null): static
    {
        if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59) {
            throw new ChronosException(sprintf('Invalid time: %d:%d:%d.', $hour, $minute, $second));
        }

        return static::now($tz)->setTime($hour, $minute, $second);
    }

    /**
     * Строгий разбор по формату PHP (предупреждения — например «31 февраля» — тоже ошибка).
     */
    public static function createFromFormat(string $format, string $time, DateTimeZone|string|null $tz = null): static
    {
        $dt = DateTimeImmutable::createFromFormat($format, $time, self::toTimezone($tz));
        $errors = DateTimeImmutable::getLastErrors();

        if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            $messages = $errors !== false ? array_merge($errors['errors'], $errors['warnings']) : [];
            throw new ChronosException(sprintf(
                'Could not parse "%s" with format "%s"%s',
                $time,
                $format,
                $messages ? ': ' . implode('; ', $messages) : '.'
            ));
        }

        return new static($dt);
    }

    public static function createFromTimestamp(int|float|string $timestamp, DateTimeZone|string|null $tz = null): static
    {
        if (!is_numeric($timestamp)) {
            throw new ChronosException('Timestamp must be numeric.');
        }

        $ts = $timestamp + 0;
        $sec = (int) floor($ts);
        $us = (int) round(($ts - $sec) * 1_000_000);

        $dt = (new DateTimeImmutable('@' . $sec))
            ->setTimezone(self::toTimezone($tz) ?? self::defaultTimezone());

        if ($us !== 0) {
            $dt = $dt->modify(sprintf('%+d microseconds', $us));
        }

        return new static($dt);
    }

    public static function createFromTimestampMs(int $milliseconds, DateTimeZone|string|null $tz = null): static
    {
        return static::createFromTimestamp($milliseconds / 1000, $tz);
    }

    // =====================================================================
    //  ЯДРО: test-now (заморозка времени)
    // =====================================================================

    /**
     * Фиксирует «сейчас». null — снять. Время при этом «замёрзшее»: now() всегда возвращает его.
     */
    public static function setTestNow(self|DateTimeInterface|string|null $now = null): void
    {
        self::$testNow = null; // строка разбирается по реальным часам

        if ($now === null) {
            return;
        }

        self::$testNow = $now instanceof self ? $now : new static($now);
    }

    public static function getTestNow(): ?self
    {
        return self::$testNow;
    }

    public static function hasTestNow(): bool
    {
        return self::$testNow !== null;
    }

    /**
     * Выполняет $callback с зафиксированным «сейчас», затем восстанавливает прежнее состояние.
     */
    public static function withTestNow(self|DateTimeInterface|string|null $now, callable $callback): mixed
    {
        $previous = self::$testNow;

        try {
            static::setTestNow($now);

            return $callback();
        } finally {
            self::$testNow = $previous;
        }
    }

    /**
     * Сбрасывает всё статическое состояние (test-now, локаль, начало недели, выходные, макросы).
     */
    public static function resetToDefaults(): void
    {
        self::$testNow = null;
        self::$defaultLocale = 'en';
        self::$weekStartsAt = self::MONDAY;
        self::$weekendDays = [self::SATURDAY, self::SUNDAY];
        self::$macros = [];
        self::$customLocales = [];
    }

    // =====================================================================
    //  ЯДРО: геттеры
    // =====================================================================

    public function __get(string $name): mixed
    {
        $dt = $this->dt;

        return match ($name) {
            'year' => (int) $dt->format('Y'),
            'month' => (int) $dt->format('n'),
            'day' => (int) $dt->format('j'),
            'hour' => (int) $dt->format('G'),
            'minute' => (int) $dt->format('i'),
            'second' => (int) $dt->format('s'),
            'micro', 'microsecond' => (int) $dt->format('u'),
            'dayOfWeek' => (int) $dt->format('w'),
            'dayOfWeekIso' => (int) $dt->format('N'),
            'dayOfYear' => (int) $dt->format('z') + 1,
            'weekOfYear' => (int) $dt->format('W'),
            'isoWeekYear' => (int) $dt->format('o'),
            'weekOfMonth' => (int) ceil(((int) $dt->format('j')) / 7),
            'weeksInYear' => $this->weeksInYear(),
            'daysInMonth' => (int) $dt->format('t'),
            'daysInYear' => self::leap((int) $dt->format('Y')) ? 366 : 365,
            'quarter' => (int) ceil(((int) $dt->format('n')) / 3),
            'timestamp' => $dt->getTimestamp(),
            'timezone', 'tz' => $dt->getTimezone(),
            'timezoneName', 'tzName' => $dt->getTimezone()->getName(),
            'offset' => $dt->getOffset(),
            'offsetMinutes' => intdiv($dt->getOffset(), 60),
            'offsetHours' => $dt->getOffset() / 3600.0,
            'age' => $this->diffInYears(null, false),
            'dayName' => $this->localeData()['days'][(int) $dt->format('w')],
            'shortDayName' => $this->localeData()['days_short'][(int) $dt->format('w')],
            'monthName' => $this->localeData()['months'][(int) $dt->format('n') - 1],
            'shortMonthName' => $this->localeData()['months_short'][(int) $dt->format('n') - 1],
            'locale' => $this->getLocale(),
            default => throw new ChronosException("Unknown property \"$name\"."),
        };
    }

    public function __isset(string $name): bool
    {
        try {
            return $this->__get($name) !== null;
        } catch (ChronosException) {
            return false;
        }
    }

    public function __set(string $name, mixed $value): void
    {
        throw new LogicException('Chrono is immutable: use set*()/add*()/sub*() which return a new instance.');
    }

    public function getTimestamp(): int
    {
        return $this->dt->getTimestamp();
    }

    /** Unix-время в миллисекундах. */
    public function timestampMs(): int
    {
        return $this->dt->getTimestamp() * 1000 + intdiv((int) $this->dt->format('u'), 1000);
    }

    public function isLeapYear(): bool
    {
        return self::leap((int) $this->dt->format('Y'));
    }

    /** Число ISO-недель (52 или 53) в ISO-году этой даты. */
    public function weeksInYear(): int
    {
        $isoYear = (int) $this->dt->format('o');

        return (int) (new DateTimeImmutable(sprintf('%04d-12-28', $isoYear)))->format('W');
    }

    public function copy(): static
    {
        return clone $this;
    }

    // =====================================================================
    //  ЯДРО: сеттеры
    // =====================================================================

    public function setDate(int $year, int $month, int $day): static
    {
        return $this->with($this->dt->setDate($year, $month, $day));
    }

    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): static
    {
        return $this->with($this->dt->setTime($hour, $minute, $second, $microsecond));
    }

    public function setDateTime(int $year, int $month, int $day, int $hour, int $minute, int $second = 0, int $microsecond = 0): static
    {
        return $this->with($this->dt->setDate($year, $month, $day)->setTime($hour, $minute, $second, $microsecond));
    }

    public function setTimestamp(int $timestamp): static
    {
        return $this->with($this->dt->setTimestamp($timestamp));
    }

    public function setYear(int $year): static
    {
        return $this->setDate($year, $this->month, $this->day);
    }

    public function setMonth(int $month): static
    {
        return $this->setDate($this->year, $month, $this->day);
    }

    public function setDay(int $day): static
    {
        return $this->setDate($this->year, $this->month, $day);
    }

    public function setHour(int $hour): static
    {
        return $this->setTime($hour, $this->minute, $this->second, $this->micro);
    }

    public function setMinute(int $minute): static
    {
        return $this->setTime($this->hour, $minute, $this->second, $this->micro);
    }

    public function setSecond(int $second): static
    {
        return $this->setTime($this->hour, $this->minute, $second, $this->micro);
    }

    public function setMicrosecond(int $microsecond): static
    {
        return $this->setTime($this->hour, $this->minute, $this->second, $microsecond);
    }

    // =====================================================================
    //  ЯДРО: арифметика
    // =====================================================================

    public function addYears(int $n): static
    {
        return $this->with($this->dt->modify(sprintf('%+d years', $n)));
    }

    public function subYears(int $n): static
    {
        return $this->addYears(-$n);
    }

    public function addMonths(int $n): static
    {
        return $this->with($this->dt->modify(sprintf('%+d months', $n)));
    }

    public function subMonths(int $n): static
    {
        return $this->addMonths(-$n);
    }

    /** Месяцы без «переполнения»: 31 янв + 1 мес = 28/29 фев. */
    public function addMonthsNoOverflow(int $n): static
    {
        $total = $this->year * 12 + ($this->month - 1) + $n;
        $year = (int) floor($total / 12);
        $month = $total - $year * 12 + 1;
        $day = min($this->day, self::daysInMonthOf($year, $month));

        return $this->with($this->dt->setDate($year, $month, $day));
    }

    public function subMonthsNoOverflow(int $n): static
    {
        return $this->addMonthsNoOverflow(-$n);
    }

    public function addYearsNoOverflow(int $n): static
    {
        return $this->addMonthsNoOverflow($n * 12);
    }

    public function subYearsNoOverflow(int $n): static
    {
        return $this->addMonthsNoOverflow(-$n * 12);
    }

    public function addQuarters(int $n): static
    {
        return $this->addMonths($n * 3);
    }

    public function subQuarters(int $n): static
    {
        return $this->addMonths(-$n * 3);
    }

    public function addWeeks(int $n): static
    {
        return $this->addDays($n * 7);
    }

    public function subWeeks(int $n): static
    {
        return $this->addDays(-$n * 7);
    }

    /** Календарные дни: «настенное» время сохраняется и через переход на летнее время. */
    public function addDays(int $n): static
    {
        return $this->with($this->dt->modify(sprintf('%+d days', $n)));
    }

    public function subDays(int $n): static
    {
        return $this->addDays(-$n);
    }

    /** Часы/минуты/секунды — реальное прошедшее время (через DST тоже). */
    public function addHours(int $n): static
    {
        return $this->addElapsedSeconds($n * 3600);
    }

    public function subHours(int $n): static
    {
        return $this->addHours(-$n);
    }

    public function addMinutes(int $n): static
    {
        return $this->addElapsedSeconds($n * 60);
    }

    public function subMinutes(int $n): static
    {
        return $this->addMinutes(-$n);
    }

    public function addSeconds(int $n): static
    {
        return $this->addElapsedSeconds($n);
    }

    public function subSeconds(int $n): static
    {
        return $this->addSeconds(-$n);
    }

    public function addMicroseconds(int $n): static
    {
        return $this->with($this->dt->modify(sprintf('%+d microseconds', $n)));
    }

    public function subMicroseconds(int $n): static
    {
        return $this->addMicroseconds(-$n);
    }

    /** Прибавить рабочие дни (выходные пропускаются; см. setWeekendDays). */
    public function addWeekdays(int $n): static
    {
        $step = $n < 0 ? -1 : 1;
        $left = abs($n);
        $date = $this;

        while ($left > 0) {
            $date = $date->addDays($step);
            if ($date->isWeekday()) {
                $left--;
            }
        }

        return $date;
    }

    public function subWeekdays(int $n): static
    {
        return $this->addWeekdays(-$n);
    }

    public function add(DateInterval|string $interval): static
    {
        return $this->with($this->dt->add(self::toInterval($interval)));
    }

    public function sub(DateInterval|string $interval): static
    {
        return $this->with($this->dt->sub(self::toInterval($interval)));
    }

    // =====================================================================
    //  ЯДРО: границы периодов
    // =====================================================================

    public function startOfSecond(): static
    {
        return $this->setTime($this->hour, $this->minute, $this->second, 0);
    }

    public function endOfSecond(): static
    {
        return $this->setTime($this->hour, $this->minute, $this->second, 999_999);
    }

    public function startOfMinute(): static
    {
        return $this->setTime($this->hour, $this->minute, 0, 0);
    }

    public function endOfMinute(): static
    {
        return $this->setTime($this->hour, $this->minute, 59, 999_999);
    }

    public function startOfHour(): static
    {
        return $this->setTime($this->hour, 0, 0, 0);
    }

    public function endOfHour(): static
    {
        return $this->setTime($this->hour, 59, 59, 999_999);
    }

    public function startOfDay(): static
    {
        return $this->setTime(0, 0, 0, 0);
    }

    public function endOfDay(): static
    {
        return $this->setTime(23, 59, 59, 999_999);
    }

    public function startOfWeek(?int $weekStartsAt = null): static
    {
        $start = self::validDow($weekStartsAt ?? self::$weekStartsAt);
        $diff = ($this->dayOfWeek - $start + 7) % 7;

        return $this->subDays($diff)->startOfDay();
    }

    public function endOfWeek(?int $weekStartsAt = null): static
    {
        return $this->startOfWeek($weekStartsAt)->addDays(6)->endOfDay();
    }

    public function startOfMonth(): static
    {
        return $this->setDate($this->year, $this->month, 1)->startOfDay();
    }

    public function endOfMonth(): static
    {
        return $this->setDate($this->year, $this->month, $this->daysInMonth)->endOfDay();
    }

    public function startOfQuarter(): static
    {
        return $this->setDate($this->year, ($this->quarter - 1) * 3 + 1, 1)->startOfDay();
    }

    public function endOfQuarter(): static
    {
        return $this->startOfQuarter()->addMonthsNoOverflow(2)->endOfMonth();
    }

    public function startOfYear(): static
    {
        return $this->setDate($this->year, 1, 1)->startOfDay();
    }

    public function endOfYear(): static
    {
        return $this->setDate($this->year, 12, 31)->endOfDay();
    }

    /** startOf('day'|'week'|'month'|...) — единица в ед. или мн. числе. */
    public function startOf(string $unit): static
    {
        return match (self::normalizeUnit($unit)) {
            'second' => $this->startOfSecond(),
            'minute' => $this->startOfMinute(),
            'hour' => $this->startOfHour(),
            'day' => $this->startOfDay(),
            'week' => $this->startOfWeek(),
            'month' => $this->startOfMonth(),
            'quarter' => $this->startOfQuarter(),
            'year' => $this->startOfYear(),
            default => throw new ChronosException("Unknown unit \"$unit\"."),
        };
    }

    public function endOf(string $unit): static
    {
        return match (self::normalizeUnit($unit)) {
            'second' => $this->endOfSecond(),
            'minute' => $this->endOfMinute(),
            'hour' => $this->endOfHour(),
            'day' => $this->endOfDay(),
            'week' => $this->endOfWeek(),
            'month' => $this->endOfMonth(),
            'quarter' => $this->endOfQuarter(),
            'year' => $this->endOfYear(),
            default => throw new ChronosException("Unknown unit \"$unit\"."),
        };
    }

    // =====================================================================
    //  ЯДРО: сравнение
    // =====================================================================

    /** -1, 0 или 1 — по моменту времени (таймзоны учитываются). */
    public function compare(self|DateTimeInterface|string|null $other = null): int
    {
        return $this->dt <=> $this->resolve($other)->dt;
    }

    public function eq(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) === 0;
    }

    public function ne(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) !== 0;
    }

    public function gt(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) > 0;
    }

    public function gte(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) >= 0;
    }

    public function lt(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) < 0;
    }

    public function lte(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->compare($other) <= 0;
    }

    /** Попадание в интервал; границы можно передать в любом порядке. */
    public function between(
        self|DateTimeInterface|string $a,
        self|DateTimeInterface|string $b,
        bool $inclusive = true,
    ): bool {
        $a = $this->resolve($a);
        $b = $this->resolve($b);

        if ($a->gt($b)) {
            [$a, $b] = [$b, $a];
        }

        return $inclusive
            ? $this->gte($a) && $this->lte($b)
            : $this->gt($a) && $this->lt($b);
    }

    /** Более ранняя из двух дат (по умолчанию вторая — «сейчас»). */
    public function min(self|DateTimeInterface|string|null $other = null): static
    {
        $other = $this->resolve($other);

        return $this->lte($other) ? $this->copy() : $other->copy();
    }

    public function max(self|DateTimeInterface|string|null $other = null): static
    {
        $other = $this->resolve($other);

        return $this->gte($other) ? $this->copy() : $other->copy();
    }

    /** Ближайшая к $this из двух дат (при равенстве — первая). */
    public function closest(self|DateTimeInterface|string $a, self|DateTimeInterface|string $b): static
    {
        $a = $this->resolve($a);
        $b = $this->resolve($b);

        return abs($this->microsTo($a)) <= abs($this->microsTo($b)) ? $a : $b;
    }

    public function farthest(self|DateTimeInterface|string $a, self|DateTimeInterface|string $b): static
    {
        $a = $this->resolve($a);
        $b = $this->resolve($b);

        return abs($this->microsTo($a)) >= abs($this->microsTo($b)) ? $a : $b;
    }

    /** Совпадение по формату; $other приводится к таймзоне $this. */
    public function isSameAs(string $format, self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->dt->format($format) === $this->inOwnTimezone($other)->dt->format($format);
    }

    public function isSameDay(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->isSameAs('Y-m-d', $other);
    }

    public function isSameMonth(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->isSameAs('Y-m', $other);
    }

    public function isSameYear(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->isSameAs('Y', $other);
    }

    public function isSameQuarter(self|DateTimeInterface|string|null $other = null): bool
    {
        $o = $this->inOwnTimezone($other);

        return $this->year === $o->year && $this->quarter === $o->quarter;
    }

    public function isSameWeek(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->startOfWeek()->eq($this->inOwnTimezone($other)->startOfWeek());
    }

    public function isPast(): bool
    {
        return $this->lt(null);
    }

    public function isFuture(): bool
    {
        return $this->gt(null);
    }

    public function isToday(): bool
    {
        return $this->isSameDay(null);
    }

    public function isTomorrow(): bool
    {
        return $this->isSameDay($this->resolve(null)->addDays(1));
    }

    public function isYesterday(): bool
    {
        return $this->isSameDay($this->resolve(null)->subDays(1));
    }

    public function isCurrentMonth(): bool
    {
        return $this->isSameMonth(null);
    }

    public function isCurrentYear(): bool
    {
        return $this->isSameYear(null);
    }

    public function isDayOfWeek(int $dayOfWeek): bool
    {
        return $this->dayOfWeek === self::validDow($dayOfWeek);
    }

    public function isWeekend(): bool
    {
        return in_array($this->dayOfWeek, self::$weekendDays, true);
    }

    public function isWeekday(): bool
    {
        return !$this->isWeekend();
    }

    /** Совпадают месяц и день (год игнорируется). По умолчанию сравнение с «сейчас». */
    public function isBirthday(self|DateTimeInterface|string|null $other = null): bool
    {
        return $this->isSameAs('md', $other);
    }

    // =====================================================================
    //  ЯДРО: разница
    // =====================================================================

    /** Сырой DateInterval от $this до $other. */
    public function diff(self|DateTimeInterface|string|null $other = null, bool $absolute = false): DateInterval
    {
        return $this->dt->diff($this->resolve($other)->dt, $absolute);
    }

    /** Секунды (целые, усечение к нулю). Знак: other − this, если $absolute = false. */
    public function diffInSeconds(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $value = intdiv($this->microsTo($this->resolve($other)), 1_000_000);

        return $absolute ? abs($value) : $value;
    }

    public function diffInMinutes(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $value = intdiv($this->diffInSeconds($other, false), 60);

        return $absolute ? abs($value) : $value;
    }

    public function diffInHours(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $value = intdiv($this->diffInSeconds($other, false), 3600);

        return $absolute ? abs($value) : $value;
    }

    /** Полные календарные сутки (в DST-сутки по 23/25 часов считается как 1 день). */
    public function diffInDays(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $iv = $this->diff($other);
        $value = (int) $iv->days * ($iv->invert ? -1 : 1);

        return $absolute ? abs($value) : $value;
    }

    public function diffInWeeks(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $value = intdiv($this->diffInDays($other, false), 7);

        return $absolute ? abs($value) : $value;
    }

    public function diffInMonths(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $iv = $this->diff($other);
        $value = ($iv->y * 12 + $iv->m) * ($iv->invert ? -1 : 1);

        return $absolute ? abs($value) : $value;
    }

    public function diffInYears(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $iv = $this->diff($other);
        $value = $iv->y * ($iv->invert ? -1 : 1);

        return $absolute ? abs($value) : $value;
    }

    /**
     * Число рабочих дней в промежутке [this, other) по календарным дням.
     */
    public function diffInWeekdays(self|DateTimeInterface|string|null $other = null, bool $absolute = true): int
    {
        $to = $this->inOwnTimezone($other)->startOfDay();
        $from = $this->startOfDay();
        $sign = 1;

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
            $sign = -1;
        }

        $days = $from->diffInDays($to);
        $weekdaysPerWeek = 7 - count(self::$weekendDays);
        $count = intdiv($days, 7) * $weekdaysPerWeek;
        $offset = intdiv($days, 7) * 7;

        for ($i = 0; $i < $days % 7; $i++) {
            if ($from->addDays($offset + $i)->isWeekday()) {
                $count++;
            }
        }

        return $absolute ? $count : $count * $sign;
    }

    // =====================================================================
    //  ЯДРО: форматирование
    // =====================================================================

    public function format(string $format): string
    {
        return $this->dt->format($format);
    }

    public function toDateString(): string
    {
        return $this->dt->format('Y-m-d');
    }

    public function toTimeString(): string
    {
        return $this->dt->format('H:i:s');
    }

    public function toDateTimeString(): string
    {
        return $this->dt->format('Y-m-d H:i:s');
    }

    public function toDateTimeLocalString(): string
    {
        return $this->dt->format('Y-m-d\TH:i:s');
    }

    public function toFormattedDateString(): string
    {
        return $this->dt->format('M j, Y');
    }

    /** 2026-11-04T12:00:00+00:00 */
    public function toIso8601String(): string
    {
        return $this->dt->format(DATE_ATOM);
    }

    public function toAtomString(): string
    {
        return $this->dt->format(DATE_ATOM);
    }

    /** 2026-11-04T10:00:00.000Z — всегда в UTC, миллисекунды (формат JS Date). */
    public function toIso8601ZuluString(): string
    {
        return $this->dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    public function toRfc2822String(): string
    {
        return $this->dt->format(DATE_RFC2822);
    }

    /** Формат HTTP-заголовков (Date, Last-Modified, Expires): Wed, 04 Nov 2026 12:00:00 GMT */
    public function toRfc7231String(): string
    {
        return $this->dt->setTimezone(new DateTimeZone('UTC'))->format('D, d M Y H:i:s \G\M\T');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'dayOfWeek' => $this->dayOfWeek,
            'dayOfYear' => $this->dayOfYear,
            'hour' => $this->hour,
            'minute' => $this->minute,
            'second' => $this->second,
            'micro' => $this->micro,
            'timestamp' => $this->timestamp,
            'formatted' => $this->toDateTimeString(),
            'timezone' => $this->timezoneName,
        ];
    }

    public function toDateTimeImmutable(): DateTimeImmutable
    {
        return $this->dt;
    }

    public function toDateTime(): DateTime
    {
        return DateTime::createFromImmutable($this->dt);
    }

    public function __toString(): string
    {
        return $this->toDateTimeString();
    }

    public function jsonSerialize(): string
    {
        return $this->toIso8601ZuluString();
    }

    // =====================================================================
    //  ЯДРО: таймзоны
    // =====================================================================

    public function getTimezone(): DateTimeZone
    {
        return $this->dt->getTimezone();
    }

    /** Тот же момент времени, другая таймзона (часы на стене меняются). */
    public function setTimezone(DateTimeZone|string $tz): static
    {
        return $this->with($this->dt->setTimezone(self::requireTimezone($tz)));
    }

    public function tz(DateTimeZone|string $tz): static
    {
        return $this->setTimezone($tz);
    }

    public function utc(): static
    {
        return $this->setTimezone('UTC');
    }

    /** Те же «настенные» часы, но в другой таймзоне (меняется момент времени). */
    public function shiftTimezone(DateTimeZone|string $tz): static
    {
        $zone = self::requireTimezone($tz);

        return $this->with(new DateTimeImmutable($this->dt->format('Y-m-d H:i:s.u'), $zone));
    }

    public function isDST(): bool
    {
        return $this->dt->format('I') === '1';
    }

    public function isUtc(): bool
    {
        return in_array(strtoupper($this->timezoneName), ['UTC', 'Z', 'GMT', '+00:00'], true);
    }

    // =====================================================================
    //  ВТОРОСТЕПЕННОЕ: календарные помощники
    // =====================================================================

    public static function setWeekStartsAt(int $dayOfWeek): void
    {
        self::$weekStartsAt = self::validDow($dayOfWeek);
    }

    public static function getWeekStartsAt(): int
    {
        return self::$weekStartsAt;
    }

    /** @param list<int> $days например [Chrono::FRIDAY, Chrono::SATURDAY] */
    public static function setWeekendDays(array $days): void
    {
        self::$weekendDays = array_values(array_map(self::validDow(...), $days));
    }

    /** @return list<int> */
    public static function getWeekendDays(): array
    {
        return self::$weekendDays;
    }

    /** Следующий (строго позже) указанный день недели в 00:00. Без аргумента — тот же день недели. */
    public function next(?int $dayOfWeek = null): static
    {
        $dow = self::validDow($dayOfWeek ?? $this->dayOfWeek);
        $diff = ($dow - $this->dayOfWeek + 7) % 7 ?: 7;

        return $this->startOfDay()->addDays($diff);
    }

    /** Предыдущий (строго раньше) указанный день недели в 00:00. */
    public function previous(?int $dayOfWeek = null): static
    {
        $dow = self::validDow($dayOfWeek ?? $this->dayOfWeek);
        $diff = ($this->dayOfWeek - $dow + 7) % 7 ?: 7;

        return $this->startOfDay()->subDays($diff);
    }

    /** Следующий рабочий день (время сохраняется). */
    public function nextWeekday(): static
    {
        return $this->addWeekdays(1);
    }

    public function previousWeekday(): static
    {
        return $this->addWeekdays(-1);
    }

    /** Первое число месяца или первый указанный день недели месяца (00:00). */
    public function firstOfMonth(?int $dayOfWeek = null): static
    {
        $first = $this->startOfMonth();

        if ($dayOfWeek === null) {
            return $first;
        }

        return $first->addDays((self::validDow($dayOfWeek) - $first->dayOfWeek + 7) % 7);
    }

    public function lastOfMonth(?int $dayOfWeek = null): static
    {
        $last = $this->endOfMonth()->startOfDay();

        if ($dayOfWeek === null) {
            return $last;
        }

        return $last->subDays(($last->dayOfWeek - self::validDow($dayOfWeek) + 7) % 7);
    }

    /** N-й указанный день недели месяца (напр. 2-й вторник); null, если такого нет. */
    public function nthOfMonth(int $nth, int $dayOfWeek): ?static
    {
        if ($nth < 1) {
            throw new ChronosException('Nth must be >= 1.');
        }

        $date = $this->firstOfMonth($dayOfWeek)->addWeeks($nth - 1);

        return $date->month === $this->month ? $date : null;
    }

    /**
     * Лениво перебирает даты от $this до $end включительно с шагом ('1 day', '2 weeks', DateInterval).
     * Только вперёд; шаг, который не двигает дату вперёд, — ChronosException.
     *
     * @return Generator<int, static>
     */
    public function rangeTo(self|DateTimeInterface|string $end, DateInterval|string $step = '1 day'): Generator
    {
        $end = $this->resolve($end);
        $interval = self::toInterval($step);
        $current = $this;

        while ($current->lte($end)) {
            yield $current;

            $next = $current->add($interval);
            if ($next->lte($current)) {
                throw new ChronosException('Range step must move the date forward.');
            }
            $current = $next;
        }
    }

    // =====================================================================
    //  ВТОРОСТЕПЕННОЕ: локали и «человеческие» даты
    // =====================================================================

    public static function setLocale(string $locale): void
    {
        self::localeDataFor($locale); // проверка существования
        self::$defaultLocale = strtolower($locale);
    }

    public static function getDefaultLocale(): string
    {
        return self::$defaultLocale;
    }

    /**
     * Регистрирует/перекрывает локаль. Недостающие ключи берутся из 'en'.
     * 'plural' — 'germanic' | 'slavic' | Closure(int): int (индекс формы).
     *
     * @param array<string, mixed> $data
     */
    public static function registerLocale(string $code, array $data): void
    {
        self::$customLocales[strtolower($code)] = array_replace(self::LOCALES['en'], $data);
    }

    /** Копия с собственной локалью (влияет на diffForHumans, translatedFormat, dayName...). */
    public function locale(string $locale): static
    {
        self::localeDataFor($locale);
        $copy = clone $this;
        $copy->localeCode = strtolower($locale);

        return $copy;
    }

    public function getLocale(): string
    {
        return $this->localeCode ?? self::$defaultLocale;
    }

    /**
     * Относительное время: "5 minutes ago", "через 3 дня", "2 days before" (если задан $other).
     *
     * @param int $parts сколько единиц показать (1 → "1 year ago", 2 → "1 year 2 months ago")
     */
    public function diffForHumans(
        self|DateTimeInterface|string|null $other = null,
        bool $absolute = false,
        int $parts = 1,
    ): string {
        $relativeToNow = $other === null;
        $ref = $this->resolve($other);
        $iv = $this->dt->diff($ref->dt);
        $isFuture = $iv->invert === 1; // $ref раньше $this  →  $this позже
        $loc = $this->localeData();

        $all = [
            'year' => $iv->y,
            'month' => $iv->m,
            'week' => intdiv($iv->d, 7),
            'day' => $iv->d % 7,
            'hour' => $iv->h,
            'minute' => $iv->i,
            'second' => $iv->s,
        ];

        $chunks = [];
        foreach ($all as $unit => $value) {
            if ($value > 0) {
                $chunks[] = self::formatUnit($loc, $unit, $value);
                if (count($chunks) >= max(1, $parts)) {
                    break;
                }
            }
        }

        if ($chunks === []) {
            return $loc['just_now'];
        }

        $time = implode(' ', $chunks);
        if ($absolute) {
            return $time;
        }

        $key = $relativeToNow
            ? ($isFuture ? 'from_now' : 'ago')
            : ($isFuture ? 'after' : 'before');

        return str_replace(':time', $time, $loc[$key]);
    }

    /**
     * Как format(), но D, l, M, F — на языке локали. В славянских локалях F даёт родительный падеж
     * ("4 ноября"), если в формате есть d или j, и именительный иначе ("ноябрь 2026").
     */
    public function translatedFormat(string $format): string
    {
        $loc = $this->localeData();
        $stripped = (string) preg_replace('/\\\\./su', '', $format);
        $genitive = !empty($loc['months_genitive']) && strpbrk($stripped, 'dj') !== false;
        $dow = $this->dayOfWeek;
        $monthIndex = $this->month - 1;

        $chars = preg_split('//u', $format, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';

        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];

            if ($c === '\\') {
                $out .= $chars[++$i] ?? '';
                continue;
            }

            $out .= match ($c) {
                'D' => $loc['days_short'][$dow],
                'l' => $loc['days'][$dow],
                'M' => $loc['months_short'][$monthIndex],
                'F' => $genitive ? $loc['months_genitive'][$monthIndex] : $loc['months'][$monthIndex],
                default => $this->dt->format($c),
            };
        }

        return $out;
    }

    // =====================================================================
    //  ВТОРОСТЕПЕННОЕ: макросы и магические методы
    // =====================================================================

    /**
     * Регистрирует макрос. Closure привязывается к $this (в статическом вызове — без $this);
     * остальные callable получают $this первым аргументом.
     */
    public static function macro(string $name, callable $macro): void
    {
        self::$macros[$name] = $macro;
    }

    public static function hasMacro(string $name): bool
    {
        return isset(self::$macros[$name]);
    }

    public static function flushMacros(): void
    {
        self::$macros = [];
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $name, array $arguments): mixed
    {
        if (isset(self::$macros[$name])) {
            $macro = self::$macros[$name];

            if ($macro instanceof Closure) {
                return Closure::bind($macro, $this, static::class)(...$arguments);
            }

            return $macro($this, ...$arguments);
        }

        // addDay(), subMonth(3), addMonthNoOverflow() ...
        if (preg_match('/^(add|sub)(Year|Month|Week|Weekday|Day|Hour|Minute|Second|Microsecond|Quarter)(NoOverflow)?$/', $name, $m)) {
            $method = $m[1] . $m[2] . 's' . ($m[3] ?? '');
            if (method_exists($this, $method)) {
                return $this->$method((int) ($arguments[0] ?? 1));
            }
        }

        // isMonday() ... isSunday()
        if (preg_match('/^is(Sunday|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday)$/', $name, $m)) {
            return $this->dayOfWeek === array_search(strtolower($m[1]), self::DAY_KEYS, true);
        }

        throw new BadMethodCallException(sprintf('Method %s::%s() does not exist.', static::class, $name));
    }

    /** @param array<int, mixed> $arguments */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        if (isset(self::$macros[$name])) {
            $macro = self::$macros[$name];

            if ($macro instanceof Closure) {
                return Closure::bind($macro, null, static::class)(...$arguments);
            }

            return $macro(...$arguments);
        }

        throw new BadMethodCallException(sprintf('Static method %s::%s() does not exist.', static::class, $name));
    }

    // =====================================================================
    //  Внутренности
    // =====================================================================

    private function with(DateTimeImmutable $dt): static
    {
        $copy = clone $this;
        $copy->dt = $dt;

        return $copy;
    }

    /**
     * Сдвиг на реально прошедшие секунды (через переход на летнее время тоже).
     * modify('+24 hours') для этого не годится: в PHP оно может сдвинуть «настенные» часы.
     */
    private function addElapsedSeconds(int $seconds): static
    {
        $interval = new DateInterval('PT' . abs($seconds) . 'S');

        return $this->with($seconds < 0 ? $this->dt->sub($interval) : $this->dt->add($interval));
    }

    /** Приводит аргумент к Chrono: null → «сейчас», строка разбирается в таймзоне $this. */
    private function resolve(self|DateTimeInterface|string|null $value): static
    {
        if ($value instanceof static) {
            return $value;
        }
        if ($value instanceof self) {
            return new static($value);
        }
        if ($value instanceof DateTimeInterface) {
            return new static($value);
        }

        return new static($value, $this->dt->getTimezone());
    }

    private function inOwnTimezone(self|DateTimeInterface|string|null $value): static
    {
        return $this->resolve($value)->setTimezone($this->dt->getTimezone());
    }

    /** Разница в микросекундах: other − this. */
    private function microsTo(self $other): int
    {
        $a = $this->dt->getTimestamp() * 1_000_000 + (int) $this->dt->format('u');
        $b = $other->dt->getTimestamp() * 1_000_000 + (int) $other->dt->format('u');

        return $b - $a;
    }

    private static function parseToImmutable(?string $time, ?DateTimeZone $tz): DateTimeImmutable
    {
        $time = $time === null ? 'now' : trim($time);
        if ($time === '') {
            $time = 'now';
        }

        $test = self::$testNow;
        if ($test === null) {
            return self::construct($time, $tz);
        }

        $base = $tz !== null ? $test->dt->setTimezone($tz) : $test->dt;

        if (strcasecmp($time, 'now') === 0) {
            return $base;
        }

        $info = date_parse($time);
        if ($info['error_count'] > 0) {
            return self::construct($time, $tz); // бросит понятную ошибку
        }

        $hasZone = !empty($info['is_localtime']);
        $fullDate = $info['year'] !== false && $info['month'] !== false && $info['day'] !== false;
        if ($hasZone || $fullDate) {
            return self::construct($time, $tz); // абсолютная точка — часы не нужны
        }

        try {
            $result = $base->modify($time);
        } catch (Throwable $e) {
            throw new ChronosException("Could not parse \"$time\": " . $e->getMessage(), 0, $e);
        }
        if ($result === false) {
            throw new ChronosException("Could not parse \"$time\".");
        }

        // «Dec 25» без времени → полночь (как в конструкторе DateTime)
        $hasDatePart = $info['year'] !== false || $info['month'] !== false || $info['day'] !== false;
        if ($hasDatePart && $info['hour'] === false) {
            $result = $result->setTime(0, 0, 0, 0);
        }

        return $result;
    }

    private static function construct(string $time, ?DateTimeZone $tz): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($time, $tz);
        } catch (Throwable $e) {
            throw new ChronosException("Could not parse \"$time\": " . $e->getMessage(), 0, $e);
        }
    }

    private static function toTimezone(DateTimeZone|string|null $tz): ?DateTimeZone
    {
        if ($tz === null || $tz instanceof DateTimeZone) {
            return $tz;
        }

        try {
            return new DateTimeZone($tz);
        } catch (Throwable $e) {
            throw new ChronosException("Unknown or invalid timezone \"$tz\".", 0, $e);
        }
    }

    private static function requireTimezone(DateTimeZone|string $tz): DateTimeZone
    {
        return self::toTimezone($tz) ?? self::defaultTimezone();
    }

    private static function defaultTimezone(): DateTimeZone
    {
        return new DateTimeZone(date_default_timezone_get());
    }

    private static function toInterval(DateInterval|string $interval): DateInterval
    {
        if ($interval instanceof DateInterval) {
            return $interval;
        }

        try {
            $parsed = @DateInterval::createFromDateString($interval);
        } catch (Throwable $e) { // PHP 8.4+ бросает исключение вместо false
            throw new ChronosException("Invalid interval \"$interval\".", 0, $e);
        }

        if ($parsed === false) {
            throw new ChronosException("Invalid interval \"$interval\".");
        }

        return $parsed;
    }

    private static function normalizeUnit(string $unit): string
    {
        $unit = strtolower(trim($unit));

        return str_ends_with($unit, 's') ? substr($unit, 0, -1) : $unit;
    }

    private static function validDow(int $dow): int
    {
        if ($dow < 0 || $dow > 6) {
            throw new ChronosException("Day of week must be 0 (Sunday) .. 6 (Saturday), got $dow.");
        }

        return $dow;
    }

    private static function leap(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function daysInMonthOf(int $year, int $month): int
    {
        return match ($month) {
            2 => self::leap($year) ? 29 : 28,
            4, 6, 9, 11 => 30,
            default => 31,
        };
    }

    /** @return array<string, mixed> */
    private static function localeDataFor(string $code): array
    {
        $code = strtolower($code);

        return self::$customLocales[$code]
            ?? self::LOCALES[$code]
            ?? throw new ChronosException("Locale \"$code\" is not registered.");
    }

    /** @return array<string, mixed> */
    private function localeData(): array
    {
        return self::localeDataFor($this->getLocale());
    }

    /** @param array<string, mixed> $loc */
    private static function formatUnit(array $loc, string $unit, int $count): string
    {
        $forms = $loc['units'][$unit];
        $index = min(self::pluralIndex($loc['plural'], $count), count($forms) - 1);

        return str_replace([':count', ':unit'], [(string) $count, $forms[$index]], $loc['unit_format']);
    }

    private static function pluralIndex(string|Closure $rule, int $n): int
    {
        if ($rule instanceof Closure) {
            return $rule($n);
        }

        if ($rule === 'slavic') {
            $m10 = $n % 10;
            $m100 = $n % 100;

            if ($m10 === 1 && $m100 !== 11) {
                return 0;
            }
            if ($m10 >= 2 && $m10 <= 4 && ($m100 < 12 || $m100 > 14)) {
                return 1;
            }

            return 2;
        }

        return $n === 1 ? 0 : 1;
    }
}