<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Unit;

use Atannex\Scopable\Period;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PeriodTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00')); // Wednesday, Q2
    }

    /**
     * @return array<string, array{0: string, 1: ?string, 2: ?string}>
     */
    public static function periods(): array
    {
        return [
            'today' => ['today', '2026-06-17 00:00:00', '2026-06-18 00:00:00'],
            'yesterday' => ['yesterday', '2026-06-16 00:00:00', '2026-06-17 00:00:00'],
            'tomorrow' => ['tomorrow', '2026-06-18 00:00:00', '2026-06-19 00:00:00'],
            'this week' => ['this week', '2026-06-15 00:00:00', '2026-06-22 00:00:00'],
            'last_week' => ['last_week', '2026-06-08 00:00:00', '2026-06-15 00:00:00'],
            'nextWeek' => ['nextWeek', '2026-06-22 00:00:00', '2026-06-29 00:00:00'],
            'this month' => ['this month', '2026-06-01 00:00:00', '2026-07-01 00:00:00'],
            'last month' => ['last month', '2026-05-01 00:00:00', '2026-06-01 00:00:00'],
            'next month' => ['next month', '2026-07-01 00:00:00', '2026-08-01 00:00:00'],
            'this quarter' => ['this quarter', '2026-04-01 00:00:00', '2026-07-01 00:00:00'],
            'last quarter' => ['last quarter', '2026-01-01 00:00:00', '2026-04-01 00:00:00'],
            'this year' => ['this year', '2026-01-01 00:00:00', '2027-01-01 00:00:00'],
            'last year' => ['last year', '2025-01-01 00:00:00', '2026-01-01 00:00:00'],
            'year' => ['2024', '2024-01-01 00:00:00', '2025-01-01 00:00:00'],
            'month' => ['2026-02', '2026-02-01 00:00:00', '2026-03-01 00:00:00'],
            'day' => ['2026-02-28', '2026-02-28 00:00:00', '2026-03-01 00:00:00'],
            'last 7 days' => ['last 7 days', '2026-06-10 12:00:00', '2026-06-17 12:00:00'],
            'next 2 hours' => ['next 2 hours', '2026-06-17 12:00:00', '2026-06-17 14:00:00'],
            'older than 1 year' => ['older than 1 year', null, '2025-06-17 12:00:00'],
            'past' => ['past', null, '2026-06-17 12:00:00'],
            'future' => ['future', '2026-06-17 12:00:00', null],
            'last 7 calendar days' => ['last 7 calendar days', '2026-06-11 00:00:00', '2026-06-18 00:00:00'],
        ];
    }

    #[DataProvider('periods')]
    public function test_parsing_and_bounds(string $input, ?string $start, ?string $end): void
    {
        $period = Period::parse($input);

        $this->assertSame($start, $period->start?->setTimezone('UTC')->toDateTimeString(), "start of {$input}");
        $this->assertSame($end, $period->end?->setTimezone('UTC')->toDateTimeString(), "end of {$input}");
    }

    public function test_month_ends_do_not_overflow(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-31 10:00:00'));

        $this->assertSame('2026-02-01', Period::lastMonth()->start->toDateString());
        $this->assertSame('2026-03-01', Period::lastMonth()->end->toDateString());
    }

    public function test_week_start_is_configurable(): void
    {
        config(['scopable.week_starts_on' => 0]);

        $this->assertSame('2026-06-14', Period::thisWeek()->start->toDateString());
    }

    public function test_calendar_timezone(): void
    {
        config(['scopable.timezone' => 'Africa/Douala']);

        $today = Period::today();

        $this->assertSame('2026-06-16 23:00:00', $today->start->setTimezone('UTC')->toDateTimeString());
        $this->assertSame('2026-06-17 23:00:00', $today->end->setTimezone('UTC')->toDateTimeString());
    }

    public function test_inclusivity(): void
    {
        $this->assertTrue(Period::since('2026-06-17 12:00:00')->contains('2026-06-17 12:00:00'));
        $this->assertFalse(Period::after('2026-06-17 12:00:00')->contains('2026-06-17 12:00:00'));
        $this->assertTrue(Period::until('2026-06-17 12:00:00')->contains('2026-06-17 12:00:00'));
        $this->assertFalse(Period::before('2026-06-17 12:00:00')->contains('2026-06-17 12:00:00'));
        $this->assertTrue(Period::between('2026-06-01', '2026-06-17')->contains('2026-06-17 23:59:59'), 'date-only end covers the day');
        $this->assertTrue(Period::between('2026-06-01 00:00', '2026-06-17 10:00')->contains('2026-06-17 10:00:00'));
        $this->assertFalse(Period::between('2026-06-01 00:00', '2026-06-17 10:00')->contains('2026-06-17 10:00:01'));
        $this->assertFalse(Period::today()->contains(null));
    }

    public function test_labels(): void
    {
        $this->assertSame('within the last 1 week', Period::parse('last 1 week')->describe());
        $this->assertSame('in May 2026', Period::inMonth(5)->describe());
    }

    public function test_nonsense_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Period::parse('whenever');
    }
}
