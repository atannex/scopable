<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Atannex\Scopable\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A span of time, open on either side. Calendar periods (today, this week,
 * on 2026-05-03) are cut in the calendar timezone (scopable.timezone);
 * queries compare the raw column against bounds (col >= start AND col < end),
 * so indexes are used and no DATE() function runs per row.
 */
final readonly class Period
{
    private function __construct(
        public ?CarbonImmutable $start,
        public ?CarbonImmutable $end,
        public bool $startInclusive = true,
        public bool $endInclusive = false,
        public string $label = '',
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Calendar periods
    |--------------------------------------------------------------------------
    */

    public static function today(): self
    {
        return self::day(Clock::local(), 'today');
    }

    public static function yesterday(): self
    {
        return self::day(Clock::local()->subDay(), 'yesterday');
    }

    public static function tomorrow(): self
    {
        return self::day(Clock::local()->addDay(), 'tomorrow');
    }

    public static function thisWeek(): self
    {
        return self::week(0, 'this week');
    }

    public static function lastWeek(): self
    {
        return self::week(-1, 'last week');
    }

    public static function nextWeek(): self
    {
        return self::week(1, 'next week');
    }

    public static function thisMonth(): self
    {
        return self::month(0, 'this month');
    }

    public static function lastMonth(): self
    {
        return self::month(-1, 'last month');
    }

    public static function nextMonth(): self
    {
        return self::month(1, 'next month');
    }

    public static function thisQuarter(): self
    {
        return self::quarter(0, 'this quarter');
    }

    public static function lastQuarter(): self
    {
        return self::quarter(-1, 'last quarter');
    }

    public static function nextQuarter(): self
    {
        return self::quarter(1, 'next quarter');
    }

    public static function thisYear(): self
    {
        return self::inYear(Clock::local()->year)->named('this year');
    }

    public static function lastYear(): self
    {
        return self::inYear(Clock::local()->year - 1)->named('last year');
    }

    public static function nextYear(): self
    {
        return self::inYear(Clock::local()->year + 1)->named('next year');
    }

    /** A calendar day (a date string is read in the calendar timezone). */
    public static function on(mixed $date): self
    {
        $day = Clock::parse($date, Clock::calendarTimezone())->setTimezone(Clock::calendarTimezone());

        return self::day($day, 'on '.$day->toDateString());
    }

    public static function inYear(int $year): self
    {
        $start = CarbonImmutable::create($year, 1, 1, 0, 0, 0, Clock::calendarTimezone());

        return new self($start, $start->addYear(), label: "in {$year}");
    }

    /** inMonth(5) is May this year; inMonth(2025, 5) is May 2025. */
    public static function inMonth(int $yearOrMonth, ?int $month = null): self
    {
        [$year, $month] = $month === null ? [Clock::local()->year, $yearOrMonth] : [$yearOrMonth, $month];

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Month must be 1-12, got {$month}.");
        }

        $start = CarbonImmutable::create($year, $month, 1, 0, 0, 0, Clock::calendarTimezone());

        return new self($start, $start->addMonth(), label: 'in '.$start->format('F Y'));
    }

    /*
    |--------------------------------------------------------------------------
    | Bounds
    |--------------------------------------------------------------------------
    */

    /**
     * Inclusive on both ends. A date-only end ("2026-05-31") covers that whole day.
     */
    public static function between(mixed $from, mixed $to): self
    {
        $start = Clock::parse($from, Clock::calendarTimezone());

        if (self::isDateOnly($to)) {
            $end = Clock::parse($to, Clock::calendarTimezone())->startOfDay()->addDay();

            return new self($start, $end, label: 'between '.$start->toDateTimeString().' and '.$to);
        }

        $end = Clock::parse($to, Clock::calendarTimezone());

        return new self($start, $end, endInclusive: true, label: 'between '.$start->toDateTimeString().' and '.$end->toDateTimeString());
    }

    /** Strictly after. */
    public static function after(mixed $date): self
    {
        $date = Clock::parse($date, Clock::calendarTimezone());

        return new self($date, null, startInclusive: false, label: 'after '.$date->toDateTimeString());
    }

    /** At or after. */
    public static function since(mixed $date): self
    {
        $date = Clock::parse($date, Clock::calendarTimezone());

        return new self($date, null, label: 'since '.$date->toDateTimeString());
    }

    /** Strictly before. */
    public static function before(mixed $date): self
    {
        $date = Clock::parse($date, Clock::calendarTimezone());

        return new self(null, $date, label: 'before '.$date->toDateTimeString());
    }

    /** At or before. */
    public static function until(mixed $date): self
    {
        $date = Clock::parse($date, Clock::calendarTimezone());

        return new self(null, $date, endInclusive: true, label: 'until '.$date->toDateTimeString());
    }

    /*
    |--------------------------------------------------------------------------
    | Relative to now
    |--------------------------------------------------------------------------
    */

    /**
     * The last $days calendar days, today included (lastDays(7): six days ago
     * through today), for day-based stats.
     */
    public static function lastDays(int $days): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException("lastDays() needs at least one day, got {$days}.");
        }

        $today = Clock::local()->startOfDay();

        return new self($today->subDays($days - 1), $today->addDay(), label: "the last {$days} days");
    }

    /** The last $duration, up to and including now. */
    public static function within(mixed $duration): self
    {
        $interval = Clock::interval($duration);
        $now = Clock::now();

        return new self($now->sub($interval), $now, endInclusive: true, label: 'within the last '.$interval->forHumans());
    }

    /** More than $duration ago. */
    public static function olderThan(mixed $duration): self
    {
        $interval = Clock::interval($duration);

        return new self(null, Clock::now()->sub($interval), label: 'older than '.$interval->forHumans());
    }

    /** From now through the next $duration. */
    public static function inNext(mixed $duration): self
    {
        $interval = Clock::interval($duration);
        $now = Clock::now();

        return new self($now, $now->add($interval), endInclusive: true, label: 'in the next '.$interval->forHumans());
    }

    public static function past(): self
    {
        return new self(null, Clock::now(), endInclusive: true, label: 'in the past');
    }

    public static function future(): self
    {
        return new self(Clock::now(), null, startInclusive: false, label: 'in the future');
    }

    /*
    |--------------------------------------------------------------------------
    | Parsing
    |--------------------------------------------------------------------------
    */

    /**
     * "today", "this_week", "thisMonth", "last 7 days", "next 2 hours",
     * "older than 1 year", "2026", "2026-05", "2026-05-03".
     */
    public static function parse(self|string $period): self
    {
        if ($period instanceof self) {
            return $period;
        }

        $raw = trim($period);

        if (preg_match('/^\d{4}$/', $raw)) {
            return self::inYear((int) $raw);
        }

        if (preg_match('/^(\d{4})-(\d{1,2})$/', $raw, $m)) {
            return self::inMonth((int) $m[1], (int) $m[2]);
        }

        if (self::isDateOnly($raw)) {
            return self::on($raw);
        }

        $text = str_contains($raw, ' ') ? strtolower($raw) : str_replace('_', ' ', Str::snake($raw));
        $text = preg_replace('/\s+/', ' ', $text);

        $fixed = [
            'today' => 'today', 'yesterday' => 'yesterday', 'tomorrow' => 'tomorrow',
            'this week' => 'thisWeek', 'last week' => 'lastWeek', 'next week' => 'nextWeek',
            'this month' => 'thisMonth', 'last month' => 'lastMonth', 'next month' => 'nextMonth',
            'this quarter' => 'thisQuarter', 'last quarter' => 'lastQuarter', 'next quarter' => 'nextQuarter',
            'this year' => 'thisYear', 'last year' => 'lastYear', 'next year' => 'nextYear',
            'past' => 'past', 'in the past' => 'past', 'future' => 'future', 'in the future' => 'future',
        ];

        if (isset($fixed[$text])) {
            $factory = $fixed[$text];

            return self::$factory();
        }

        return match (true) {
            (bool) preg_match('/^(?:last|past) (\d+) calendar days?$/', $text, $m) => self::lastDays((int) $m[1]),
            (bool) preg_match('/^(?:last|past|within|within the last|in the last) (.+)$/', $text, $m) => self::within($m[1]),
            (bool) preg_match('/^(?:next|in the next) (.+)$/', $text, $m) => self::inNext($m[1]),
            (bool) preg_match('/^older than (.+)$/', $text, $m) => self::olderThan($m[1]),
            default => throw new InvalidArgumentException("Unknown period [{$period}]. Try 'today', 'this week', 'last 7 days', 'older than 1 year' or '2026-05'."),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Use
    |--------------------------------------------------------------------------
    */

    public function apply(Builder $query, string $column): Builder
    {
        // A date-cast column (a stats "day") is compared with dates, not datetimes.
        $dateOnly = $query->getModel()->hasCast(str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column, ['date', 'immutable_date']);
        $bind = fn (CarbonImmutable $moment) => $dateOnly ? $moment->setTimezone(Clock::calendarTimezone())->toDateString() : Clock::bind($moment);
        $column = $query->qualifyColumn($column);

        if ($this->start !== null) {
            $query->where($column, $this->startInclusive ? '>=' : '>', $bind($this->start));
        }

        if ($this->end !== null) {
            $query->where($column, $this->endInclusive ? '<=' : '<', $bind($this->end));
        }

        return $query;
    }

    /**
     * Rows whose own window [$from, $until) overlaps this period; a NULL bound
     * is open (no start yet / no end). For campaigns "running during" a range.
     */
    public function applyOverlap(Builder $query, ?string $from, ?string $until): Builder
    {
        if ($this->start !== null && $until !== null) {
            $column = $query->qualifyColumn($until);
            $start = Clock::bind($this->start);

            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '>', $start));
        }

        if ($this->end !== null && $from !== null) {
            $column = $query->qualifyColumn($from);
            $end = Clock::bind($this->end);

            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $this->endInclusive ? '<=' : '<', $end));
        }

        return $query;
    }

    /**
     * Constrain any query: a date alias on a HasScopes model, otherwise a column.
     */
    public static function constrain(Builder $query, string $date, self|string $period): Builder
    {
        $model = $query->getModel();

        return method_exists($model, 'scopeDefinitions')
            ? $model::scopeDefinitions()->applyPeriod($query, $date, $period)
            : self::parse($period)->apply($query, $date);
    }

    public function contains(mixed $date): bool
    {
        $date = Clock::value($date);

        if ($date === null) {
            return false;
        }

        if ($this->start !== null && ($this->startInclusive ? $date->lessThan($this->start) : $date->lessThanOrEqualTo($this->start))) {
            return false;
        }

        if ($this->end !== null && ($this->endInclusive ? $date->greaterThan($this->end) : $date->greaterThanOrEqualTo($this->end))) {
            return false;
        }

        return true;
    }

    public function named(string $label): self
    {
        return new self($this->start, $this->end, $this->startInclusive, $this->endInclusive, $label);
    }

    public function describe(): string
    {
        return $this->label;
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private static function day(CarbonImmutable $day, string $label): self
    {
        $start = $day->setTimezone(Clock::calendarTimezone())->startOfDay();

        return new self($start, $start->addDay(), label: $label);
    }

    private static function week(int $offset, string $label): self
    {
        $start = Clock::local()->startOfWeek(Clock::weekStartsOn())->addWeeks($offset);

        return new self($start, $start->addWeek(), label: $label);
    }

    private static function month(int $offset, string $label): self
    {
        $start = Clock::local()->startOfMonth()->addMonthsNoOverflow($offset);

        return new self($start, $start->addMonthNoOverflow(), label: $label);
    }

    private static function quarter(int $offset, string $label): self
    {
        $start = Clock::local()->startOfQuarter()->addMonthsNoOverflow(3 * $offset);

        return new self($start, $start->addMonthsNoOverflow(3), label: $label);
    }

    private static function isDateOnly(mixed $value): bool
    {
        return is_string($value) && (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value));
    }
}
