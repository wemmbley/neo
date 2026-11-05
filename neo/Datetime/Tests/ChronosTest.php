<?php

use Neo\Datetime\Chronos;
use Neo\Datetime\ChronosException;

/**
 * ВТОРОСТЕПЕННОЕ: diffForHumans, локали, translatedFormat, календарные помощники,
 * диапазоны, макросы, наследование.
 *
 * «Сейчас» везде заморожено: среда, 2026-11-04 12:00:00 UTC.
 */

function macroLabel(Chronos $date, string $prefix): string
{
    return $prefix . $date->toDateString();
}

class SubChronos extends Chronos
{
}

beforeEach(function () {
    Chronos::resetToDefaults();
    date_default_timezone_set('UTC');
    Chronos::setTestNow('2026-11-04 12:00:00');
});

afterEach(function () {
    Chronos::resetToDefaults();
});

// ---------------------------------------------------------------------
describe('diffForHumans (en)', function () {
    it('describes the past and the future relative to now', function (string $date, string $expected) {
        expect(Chronos::parse($date)->diffForHumans())->toBe($expected);
    })->with([
        ['2026-11-04 11:59:30', '30 seconds ago'],
        ['2026-11-04 11:59:00', '1 minute ago'],
        ['2026-11-04 11:55:00', '5 minutes ago'],
        ['2026-11-04 10:00:00', '2 hours ago'],
        ['2026-11-03 12:00:00', '1 day ago'],
        ['2026-10-30 12:00:00', '5 days ago'],
        ['2026-10-14 12:00:00', '3 weeks ago'],
        ['2026-10-04 12:00:00', '1 month ago'],
        ['2024-09-04 12:00:00', '2 years ago'],
        ['2026-11-04 12:00:00', 'just now'],
        ['2026-11-04 12:05:00', '5 minutes from now'],
        ['2026-11-06 12:00:00', '2 days from now'],
        ['2027-11-04 12:00:00', '1 year from now'],
    ]);

    it('says before/after when compared to another date', function () {
        $early = Chronos::parse('2026-11-04 10:00');
        $late = Chronos::parse('2026-11-04 12:00');

        expect($early->diffForHumans($late))->toBe('2 hours before');
        expect($late->diffForHumans($early))->toBe('2 hours after');
        expect($late->diffForHumans($early, true))->toBe('2 hours');
    });

    it('drops the suffix when absolute', function () {
        expect(Chronos::parse('2026-11-04 11:55')->diffForHumans(null, true))->toBe('5 minutes');
        expect(Chronos::parse('2026-11-04 12:00')->diffForHumans(null, true))->toBe('just now');
    });

    it('can show several units', function () {
        expect(Chronos::parse('2025-09-04 12:00')->diffForHumans(null, false, 2))->toBe('1 year 2 months ago');
        expect(Chronos::parse('2025-09-01 09:00')->diffForHumans(null, false, 3))->toBe('1 year 2 months 3 days ago');
        expect(Chronos::parse('2026-11-04 11:00')->diffForHumans(null, false, 3))->toBe('1 hour ago');
    });

    it('truncates instead of rounding', function () {
        expect(Chronos::parse('2026-10-04 13:00')->diffForHumans())->toBe('4 weeks ago'); // 30 дней 23 ч
        expect(Chronos::parse('2026-11-03 13:00')->diffForHumans())->toBe('23 hours ago');
    });
});

// ---------------------------------------------------------------------
describe('locales', function () {
    it('speaks Russian with correct plural forms', function (string $date, string $expected) {
        Chronos::setLocale('ru');

        expect(Chronos::parse($date)->diffForHumans())->toBe($expected);
    })->with([
        ['2026-11-04 12:00:00', 'только что'],
        ['2026-11-04 11:59:00', '1 минута назад'],
        ['2026-11-04 11:58:00', '2 минуты назад'],
        ['2026-11-04 11:55:00', '5 минут назад'],
        ['2026-11-04 01:00:00', '11 часов назад'],
        ['2026-11-04 10:00:00', '2 часа назад'],
        ['2026-11-03 15:00:00', '21 час назад'],
        ['2026-10-30 12:00:00', '5 дней назад'],
        ['2026-10-14 12:00:00', '3 недели назад'],
        ['2026-09-04 12:00:00', '2 месяца назад'],
        ['2025-11-04 12:00:00', '1 год назад'],
        ['2024-11-04 12:00:00', '2 года назад'],
        ['2021-11-04 12:00:00', '5 лет назад'],
        ['2026-11-07 12:00:00', 'через 3 дня'],
    ]);

    it('handles the 11–14 exception in Slavic plurals', function () {
        Chronos::setLocale('ru');

        $ago = fn (string $unit, int $n) => Chronos::now()->{'sub' . $unit}($n)->diffForHumans(null, true);

        expect($ago('Minutes', 11))->toBe('11 минут');
        expect($ago('Minutes', 14))->toBe('14 минут');
        expect($ago('Minutes', 21))->toBe('21 минута');
        expect($ago('Hours', 12))->toBe('12 часов');
        expect($ago('Hours', 22))->toBe('22 часа');
        expect($ago('Seconds', 11))->toBe('11 секунд');
        expect($ago('Seconds', 3))->toBe('3 секунды');
    });

    it('says до/после relative to another date in Russian', function () {
        Chronos::setLocale('ru');
        $early = Chronos::parse('2026-11-04 10:00');
        $late = Chronos::parse('2026-11-04 12:00');

        expect($early->diffForHumans($late))->toBe('2 часа до');
        expect($late->diffForHumans($early))->toBe('2 часа после');
    });

    it('speaks Ukrainian', function () {
        expect(Chronos::parse('2026-11-04 11:55')->locale('uk')->diffForHumans())->toBe('5 хвилин тому');
        expect(Chronos::parse('2026-11-07 12:00')->locale('uk')->diffForHumans())->toBe('через 3 дні');
        expect(Chronos::parse('2026-11-04 12:00')->locale('uk')->diffForHumans())->toBe('щойно');
    });

    it('lets an instance override the default locale and keeps it through operations', function () {
        Chronos::setLocale('ru');
        $en = Chronos::parse('2026-11-04 11:55')->locale('en');

        expect($en->diffForHumans())->toBe('5 minutes ago');
        expect($en->addMinutes(1)->diffForHumans())->toBe('4 minutes ago');
        expect($en->getLocale())->toBe('en');
        expect(Chronos::parse('2026-11-04 11:55')->getLocale())->toBe('ru');
        expect(Chronos::getDefaultLocale())->toBe('ru');
    });

    it('rejects unknown locales', function () {
        expect(fn () => Chronos::setLocale('tlh'))->toThrow(ChronosException::class);
        expect(fn () => Chronos::now()->locale('tlh'))->toThrow(ChronosException::class);
    });

    it('registers custom locales that fall back to English', function () {
        Chronos::registerLocale('shout', ['ago' => ':time AGO', 'just_now' => 'NOW']);
        $c = Chronos::parse('2026-11-04 11:55')->locale('shout');

        expect($c->diffForHumans())->toBe('5 minutes AGO');
        expect(Chronos::parse('2026-11-04 12:00')->locale('shout')->diffForHumans())->toBe('NOW');
        expect($c->monthName)->toBe('November');
    });

    it('accepts a custom plural rule', function () {
        Chronos::registerLocale('single', [
            'plural' => fn (int $n): int => 0,
        ]);

        expect(Chronos::parse('2026-11-04 11:55')->locale('single')->diffForHumans())->toBe('5 minute ago');
    });

    it('exposes localized day and month names', function () {
        $c = Chronos::parse('2026-11-04');

        expect($c->dayName)->toBe('Wednesday');
        expect($c->shortDayName)->toBe('Wed');
        expect($c->monthName)->toBe('November');
        expect($c->shortMonthName)->toBe('Nov');

        $ru = $c->locale('ru');
        expect($ru->dayName)->toBe('среда');
        expect($ru->shortDayName)->toBe('ср');
        expect($ru->monthName)->toBe('ноябрь');
        expect($ru->shortMonthName)->toBe('ноя');
        expect($ru->locale)->toBe('ru');
    });
});

// ---------------------------------------------------------------------
describe('translatedFormat', function () {
    it('translates names in Russian and picks the right month case', function (string $format, string $expected) {
        expect(Chronos::parse('2026-11-04')->locale('ru')->translatedFormat($format))->toBe($expected);
    })->with([
        ['j F Y, l', '4 ноября 2026, среда'],
        ['d F Y', '04 ноября 2026'],
        ['F Y', 'ноябрь 2026'],
        ['D, d M', 'ср, 04 ноя'],
        ['j F Y г.', '4 ноября 2026 г.'],
        ['\F: F', 'F: ноябрь'],
        ['\d F', 'd ноябрь'],
        ['Y-m-d', '2026-11-04'],
    ]);

    it('behaves like format() in English', function () {
        $c = Chronos::parse('2026-11-04 15:30');

        expect($c->translatedFormat('l, j F Y'))->toBe('Wednesday, 4 November 2026');
        expect($c->translatedFormat('jS M, H:i'))->toBe('4th Nov, 15:30');
    });

    it('uses the default locale when the instance has none', function () {
        Chronos::setLocale('uk');

        expect(Chronos::parse('2026-11-04')->translatedFormat('j F'))->toBe('4 листопада');
    });
});

// ---------------------------------------------------------------------
describe('calendar helpers', function () {
    it('finds the first and last days of a month', function () {
        $c = Chronos::parse('2026-11-18 15:00');

        expect($c->firstOfMonth()->toDateTimeString())->toBe('2026-11-01 00:00:00');
        expect($c->lastOfMonth()->toDateTimeString())->toBe('2026-11-30 00:00:00');
        expect($c->firstOfMonth(Chronos::MONDAY)->toDateString())->toBe('2026-11-02');
        expect($c->firstOfMonth(Chronos::SUNDAY)->toDateString())->toBe('2026-11-01');
        expect($c->lastOfMonth(Chronos::FRIDAY)->toDateString())->toBe('2026-11-27');
        expect($c->lastOfMonth(Chronos::MONDAY)->toDateString())->toBe('2026-11-30');
    });

    it('finds the nth weekday of a month', function () {
        $c = Chronos::parse('2026-11-18');

        expect($c->nthOfMonth(2, Chronos::WEDNESDAY)->toDateString())->toBe('2026-11-11');
        expect($c->nthOfMonth(1, Chronos::SUNDAY)->toDateString())->toBe('2026-11-01');
        expect($c->nthOfMonth(5, Chronos::MONDAY)->toDateString())->toBe('2026-11-30');
        expect($c->nthOfMonth(5, Chronos::TUESDAY))->toBeNull();
        expect(fn () => $c->nthOfMonth(0, Chronos::MONDAY))->toThrow(ChronosException::class);
    });

    it('finds the next and previous weekday name (strictly, at midnight)', function () {
        $wed = Chronos::parse('2026-11-04 12:00');

        expect($wed->next(Chronos::MONDAY)->toDateTimeString())->toBe('2026-11-09 00:00:00');
        expect($wed->next(Chronos::WEDNESDAY)->toDateString())->toBe('2026-11-11');
        expect($wed->next()->toDateString())->toBe('2026-11-11');
        expect($wed->next(Chronos::THURSDAY)->toDateString())->toBe('2026-11-05');
        expect($wed->previous(Chronos::FRIDAY)->toDateTimeString())->toBe('2026-10-30 00:00:00');
        expect($wed->previous(Chronos::WEDNESDAY)->toDateString())->toBe('2026-10-28');
        expect($wed->previous()->toDateString())->toBe('2026-10-28');
        expect($wed->previous(Chronos::TUESDAY)->toDateString())->toBe('2026-11-03');
        expect(fn () => $wed->next(9))->toThrow(ChronosException::class);
    });

    it('moves to the next and previous weekday keeping the time', function () {
        expect(Chronos::parse('2026-11-06 15:30')->nextWeekday()->toDateTimeString())->toBe('2026-11-09 15:30:00');
        expect(Chronos::parse('2026-11-09 10:00')->previousWeekday()->toDateTimeString())->toBe('2026-11-06 10:00:00');
        expect(Chronos::parse('2026-11-04 10:00')->nextWeekday()->toDateString())->toBe('2026-11-05');
    });
});

// ---------------------------------------------------------------------
describe('ranges', function () {
    it('iterates days inclusively', function () {
        $days = iterator_to_array(Chronos::parse('2026-11-01')->rangeTo('2026-11-05'), false);

        expect($days)->toHaveCount(5);
        expect($days[0]->toDateString())->toBe('2026-11-01');
        expect($days[4]->toDateString())->toBe('2026-11-05');
        expect($days[2])->toBeInstanceOf(Chronos::class);
    });

    it('supports string and DateInterval steps', function () {
        $every2 = iterator_to_array(Chronos::parse('2026-11-01')->rangeTo('2026-11-05', '2 days'), false);
        expect(array_map(fn (Chronos $c) => $c->day, $every2))->toBe([1, 3, 5]);

        $weekly = iterator_to_array(Chronos::parse('2026-11-01')->rangeTo('2026-11-30', '1 week'), false);
        expect($weekly)->toHaveCount(5);

        $hourly = iterator_to_array(
            Chronos::parse('2026-11-01 00:00')->rangeTo('2026-11-02 00:00', new DateInterval('PT12H')),
            false
        );
        expect($hourly)->toHaveCount(3);
        expect($hourly[1]->toDateTimeString())->toBe('2026-11-01 12:00:00');
    });

    it('is lazy', function () {
        $gen = Chronos::parse('2000-01-01')->rangeTo('2100-01-01');

        expect($gen->current()->toDateString())->toBe('2000-01-01');
        $gen->next();
        expect($gen->current()->toDateString())->toBe('2000-01-02');
    });

    it('yields nothing when the end is before the start', function () {
        expect(iterator_to_array(Chronos::parse('2026-11-05')->rangeTo('2026-11-01'), false))->toBe([]);
    });

    it('refuses steps that do not advance', function () {
        expect(fn () => iterator_to_array(Chronos::parse('2026-11-01')->rangeTo('2026-11-05', '0 days'), false))
            ->toThrow(ChronosException::class);
        expect(fn () => iterator_to_array(Chronos::parse('2026-11-01')->rangeTo('2026-11-05', '-1 day'), false))
            ->toThrow(ChronosException::class);
    });

    it('yields instances that keep their locale', function () {
        $first = Chronos::parse('2026-11-01')->locale('ru')->rangeTo('2026-11-03')->current();

        expect($first->getLocale())->toBe('ru');
    });
});

// ---------------------------------------------------------------------
describe('macros', function () {
    it('binds closures to the instance', function () {
        Chronos::macro('endOfBusinessDay', function () {
            return $this->setTime(18, 0);
        });

        expect(Chronos::hasMacro('endOfBusinessDay'))->toBeTrue();
        expect(Chronos::parse('2026-11-04 09:00')->endOfBusinessDay()->toDateTimeString())->toBe('2026-11-04 18:00:00');
    });

    it('passes arguments through', function () {
        Chronos::macro('addDoubleDays', function (int $n) {
            return $this->addDays($n * 2);
        });

        expect(Chronos::parse('2026-11-04')->addDoubleDays(3)->toDateString())->toBe('2026-11-10');
    });

    it('gives non-closure callables the instance as first argument', function () {
        Chronos::macro('label', 'macroLabel');

        expect(Chronos::parse('2026-11-04')->label('on '))->toBe('on 2026-11-04');
    });

    it('supports static macros', function () {
        Chronos::macro('christmas', function (int $year) {
            return Chronos::create($year, 12, 25);
        });

        expect(Chronos::christmas(2026)->toDateString())->toBe('2026-12-25');
    });

    it('can be flushed', function () {
        Chronos::macro('foo', fn () => 1);
        Chronos::flushMacros();

        expect(Chronos::hasMacro('foo'))->toBeFalse();
    });

    it('throws on unknown methods', function () {
        expect(fn () => Chronos::now()->doesNotExist())->toThrow(BadMethodCallException::class);
        expect(fn () => Chronos::doesNotExist())->toThrow(BadMethodCallException::class);
        expect(fn () => Chronos::now()->addFortnights())->toThrow(BadMethodCallException::class);
    });
});

// ---------------------------------------------------------------------
describe('inheritance', function () {
    it('returns the subclass from factories and operations', function () {
        expect(SubChronos::now())->toBeInstanceOf(SubChronos::class);
        expect(SubChronos::parse('2026-01-01')->addDays(1))->toBeInstanceOf(SubChronos::class);
        expect(SubChronos::parse('2026-01-01')->startOfMonth()->setTimezone('Asia/Tokyo'))->toBeInstanceOf(SubChronos::class);
        expect(iterator_to_array(SubChronos::parse('2026-01-01')->rangeTo('2026-01-02'), false)[1])->toBeInstanceOf(SubChronos::class);
    });

    it('compares with plain Chrono instances', function () {
        expect(SubChronos::parse('2026-01-01')->eq(Chronos::parse('2026-01-01')))->toBeTrue();
        expect(Chronos::parse('2026-01-01')->lt(SubChronos::parse('2026-01-02')))->toBeTrue();
        expect(SubChronos::parse('2026-01-01')->min(Chronos::parse('2025-01-01'))->year)->toBe(2025);
    });
});