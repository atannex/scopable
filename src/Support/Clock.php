<?php

declare(strict_types=1);

namespace Atannex\Scopable\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Time for every rule and period. "Now" follows Date::now(), so Carbon test
 * time (travelTo, setTestNow) applies. Database values are read and bound in
 * the storage timezone (app.timezone); calendar periods are cut in the
 * calendar timezone (scopable.timezone).
 */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->setTimezone(self::storageTimezone());
    }

    /**
     * The moment a state is checked at: now, or a given moment.
     */
    public static function at(mixed $at = null): CarbonImmutable
    {
        if ($at === null) {
            return self::now();
        }

        return self::parse($at, self::storageTimezone())->setTimezone(self::storageTimezone());
    }

    /**
     * A date from user input. Strings without an offset are read in $timezone.
     */
    public static function parse(mixed $value, ?string $timezone = null): CarbonImmutable
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value),
            is_int($value) => CarbonImmutable::createFromTimestamp($value, $timezone ?? self::storageTimezone()),
            is_string($value) && trim($value) !== '' => CarbonImmutable::parse($value, $timezone ?? self::storageTimezone()),
            default => throw new InvalidArgumentException('Expected a date, got '.get_debug_type($value).'.'),
        };
    }

    /**
     * A model attribute as a date (null when empty). Casts are honoured;
     * raw strings are read in the storage timezone.
     */
    public static function value(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::parse($value, self::storageTimezone());
    }

    /**
     * A date ready to bind: in the storage timezone, which is how Eloquent wrote it.
     */
    public static function bind(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone(self::storageTimezone());
    }

    /**
     * "7 days", "PT2H", 3600 (seconds), a DateInterval or a CarbonInterval.
     */
    public static function interval(mixed $duration): CarbonInterval
    {
        $interval = match (true) {
            $duration instanceof CarbonInterval => $duration,
            $duration instanceof DateInterval => CarbonInterval::instance($duration),
            is_int($duration) => CarbonInterval::seconds($duration),
            is_string($duration) && trim($duration) !== '' => CarbonInterval::make(trim($duration)),
            default => null,
        };

        if (! $interval instanceof CarbonInterval) {
            throw new InvalidArgumentException('Expected a duration such as "7 days", got '.var_export($duration, true).'.');
        }

        return $interval;
    }

    public static function storageTimezone(): string
    {
        return (string) (config('app.timezone') ?: 'UTC');
    }

    public static function calendarTimezone(): string
    {
        return (string) (config('scopable.timezone') ?: self::storageTimezone());
    }

    /**
     * Now, in the calendar timezone.
     */
    public static function local(): CarbonImmutable
    {
        return self::now()->setTimezone(self::calendarTimezone());
    }

    public static function weekStartsOn(): int
    {
        return (int) config('scopable.week_starts_on', 1);
    }
}
