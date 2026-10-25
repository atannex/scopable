<?php

declare(strict_types=1);

namespace Atannex\Scopable\Filament;

use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * A yes / no / any table filter for a declared state:
 *
 *     StateFilter::make('breaking'),
 *     StateFilter::make('live')->forState('running')->label('Running now'),
 *
 * Needs filament/filament.
 */
class StateFilter extends TernaryFilter
{
    protected ?string $stateName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(fn (): string => Str::headline($this->getStateName()));
        $this->trueLabel(fn (): string => __('Yes'));
        $this->falseLabel(fn (): string => __('No'));

        $this->queries(
            true: fn (Builder $query): Builder => $query->whereState($this->getStateName()),
            false: fn (Builder $query): Builder => $query->whereNotState($this->getStateName()),
            blank: fn (Builder $query): Builder => $query,
        );
    }

    /**
     * The state to filter by, when it differs from the filter name.
     */
    public function forState(string $state): static
    {
        $this->stateName = $state;

        return $this;
    }

    public function getStateName(): string
    {
        return $this->stateName ?? $this->getName();
    }
}
