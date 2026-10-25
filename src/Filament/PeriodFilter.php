<?php

declare(strict_types=1);

namespace Atannex\Scopable\Filament;

use Atannex\Scopable\Period;
use Atannex\Scopable\Support\Clock;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A "from / until" date filter with removable indicators:
 *
 *     PeriodFilter::make('published', __('Published')),
 *     PeriodFilter::make('post_tag.created_at', __('Tagged')),
 *
 * $date is a date alias of the model ("created", "published") or a column.
 * Days are calendar days (scopable.timezone); "until" covers the whole day.
 * Needs filament/filament.
 */
final class PeriodFilter
{
    public static function make(string $date = 'created', ?string $label = null, bool $pastOnly = false, ?string $name = null): Filter
    {
        $label ??= __('Created');

        return Filter::make($name ?? str_replace('.', '_', $date).'_period')
            ->label($label)
            ->schema([
                DatePicker::make('from')
                    ->label(__(':label from', ['label' => $label]))
                    ->native(false)
                    ->placeholder(__('Any date'))
                    ->maxDate($pastOnly ? now() : null),
                DatePicker::make('until')
                    ->label(__(':label until', ['label' => $label]))
                    ->native(false)
                    ->placeholder($pastOnly ? __('Today') : __('Any date'))
                    ->minDate(fn ($get) => $get('from') ?: null),
            ])
            ->columns(2)
            ->columnSpan(2)
            ->query(fn (Builder $query, array $data): Builder => self::apply($query, $date, $data['from'] ?? null, $data['until'] ?? null))
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                foreach (['from', 'until'] as $field) {
                    if (filled($data[$field] ?? null)) {
                        $indicators[] = Indicator::make(__(":label {$field} :date", [
                            'label' => $label,
                            'date' => Clock::parse($data[$field], Clock::calendarTimezone())->toFormattedDateString(),
                        ]))->removeField($field);
                    }
                }

                return $indicators;
            });
    }

    /**
     * "Running between": rows whose [$from, $until) window overlaps the dates.
     *
     *     PeriodFilter::window('starts_at', 'ends_at', __('Running'), name: 'running_between'),
     */
    public static function window(?string $from, ?string $until, ?string $label = null, ?string $name = null): Filter
    {
        return self::make((string) ($from ?? $until), $label ?? __('Running'), name: $name ?? 'window')
            ->query(fn (Builder $query, array $data): Builder => ($period = self::period($data['from'] ?? null, $data['until'] ?? null)) === null
                ? $query
                : $period->applyOverlap($query, $from, $until));
    }

    /**
     * The query part on its own, for custom filters.
     */
    public static function apply(Builder $query, string $date, mixed $from, mixed $until): Builder
    {
        $period = self::period($from, $until);

        return $period === null ? $query : Period::constrain($query, $date, $period);
    }

    /**
     * Whole calendar days from the picker values.
     */
    public static function period(mixed $from, mixed $until): ?Period
    {
        return match (true) {
            filled($from) && filled($until) => Period::between(self::day($from), self::day($until)),
            filled($from) => Period::since(Clock::parse(self::day($from), Clock::calendarTimezone())->startOfDay()),
            filled($until) => Period::before(Clock::parse(self::day($until), Clock::calendarTimezone())->startOfDay()->addDay()),
            default => null,
        };
    }

    /**
     * DatePicker state as a date-only string, so "until" covers the whole day.
     */
    private static function day(mixed $value): string
    {
        return Clock::parse($value, Clock::calendarTimezone())->toDateString();
    }
}
