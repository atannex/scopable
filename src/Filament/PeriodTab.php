<?php

declare(strict_types=1);

namespace Atannex\Scopable\Filament;

use Atannex\Scopable\Period;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A list-page tab for a period of a date alias or column:
 *
 *     'today' => PeriodTab::make(Click::class, 'created', 'today', __('Today'), count: true),
 *     'week' => PeriodTab::make(Contact::class, 'created', 'last 7 days', __('This week')),
 *
 * Needs filament/filament.
 */
final class PeriodTab
{
    /**
     * @param  class-string<Model>  $model
     */
    public static function make(string $model, string $date, Period|string $period, ?string $label = null, bool $count = false): Tab
    {
        $constrain = fn (Builder $query): Builder => Period::constrain($query, $date, $period);

        $tab = Tab::make($label ?? ucfirst(Period::parse($period)->describe()))
            ->modifyQueryUsing($constrain);

        return $count
            ? $tab->badge(fn (): ?int => $constrain($model::query())->count() ?: null)
            : $tab;
    }
}
