<?php

declare(strict_types=1);

namespace Atannex\Scopable\Filament;

use Atannex\Scopable\Period;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * A select of periods for one date:
 *
 *     PeriodSelectFilter::make('created', [
 *         'today' => __('Today'),
 *         'last 7 days' => __('This week'),
 *         'last month' => __('Last month'),
 *     ], __('Added')),
 *
 * Option keys are anything Period::parse() reads. Needs filament/filament.
 */
final class PeriodSelectFilter
{
    /**
     * @param  array<string, string>  $periods  period => label
     */
    public static function make(string $date, array $periods, ?string $label = null, ?string $name = null): SelectFilter
    {
        foreach (array_keys($periods) as $period) {
            Period::parse($period); // fail early on a typo
        }

        return SelectFilter::make($name ?? str_replace('.', '_', $date).'_in')
            ->label($label ?? __('Date'))
            ->options($periods)
            ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) && isset($periods[$data['value']])
                ? Period::constrain($query, $date, $data['value'])
                : $query);
    }
}
