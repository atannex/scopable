<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A date in the last $duration, up to now: fresh, new, recently active.
 */
final class Within extends Rule
{
    public readonly CarbonInterval $duration;

    public function __construct(public readonly string $column, mixed $duration)
    {
        $this->duration = Clock::interval($duration);
    }

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->whereBetween($this->qualify($query, $this->column), [Clock::bind($now->sub($this->duration)), Clock::bind($now)]);
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q
            ->whereNull($column)
            ->orWhere($column, '<', Clock::bind($now->sub($this->duration)))
            ->orWhere($column, '>', Clock::bind($now)));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $date = Clock::value($model->getAttribute($this->column));

        return $date !== null && $date->betweenIncluded($now->sub($this->duration), $now);
    }

    public function boundaries(string $model): array
    {
        return [new Boundary($this->column), new Boundary($this->column, $this->duration)];
    }

    public function describe(): string
    {
        return "{$this->column} within the last {$this->duration->forHumans()}";
    }
}
