<?php

declare(strict_types=1);

namespace Atannex\Scopable\Filament;

use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A list-page tab for one or more declared states:
 *
 *     'pending' => StateTab::make(Testimonial::class, 'pending', __('Pending'), count: true),
 *     'rejected' => StateTab::make(Testimonial::class, ['rejected', 'spam'], __('Rejected & spam'), any: true),
 *     'featured' => StateTab::make(Testimonial::class, ['approved', 'featured']),
 *
 * Several states must all hold, or any of them with $any. $count: true
 * counts with its own query (empty counts are hidden); an int (from
 * Model::countStates(), one query for every tab) is shown as given.
 * Needs filament/filament.
 *
 * @see PeriodTab for date tabs ("Today", "Last 7 days")
 */
final class StateTab
{
    /**
     * @param  class-string<Model>  $model
     * @param  string|list<string>  $states
     */
    public static function make(string $model, string|array $states, ?string $label = null, bool|int|null $count = false, bool $any = false): Tab
    {
        $states = (array) $states;
        $constrain = fn (Builder $query): Builder => $any ? $query->whereAnyState($states) : $query->whereState($states);

        $tab = Tab::make($label ?? Str::headline(implode(' ', $states)))
            ->modifyQueryUsing($constrain);

        return match (true) {
            $count === true => $tab->badge(fn (): ?int => $constrain($model::query())->count() ?: null),
            is_int($count) => $tab->badge($count),
            default => $tab,
        };
    }
}
