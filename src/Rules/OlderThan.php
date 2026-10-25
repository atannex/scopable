<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Support\Clock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A date further back than $duration: stale, archived, abandoned.
 */
final class OlderThan extends Rule
{
    public readonly CarbonInterval $duration;

    public function __construct(public readonly string $column, mixed $duration)
    {
        $this->duration = Clock::interval($duration);
    }

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->where($this->qualify($query, $this->column), '<', Clock::bind($now->sub($this->duration)));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '>=', Clock::bind($now->sub($this->duration))));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $date = Clock::value($model->getAttribute($this->column));

        return $date !== null && $date->lessThan($now->sub($this->duration));
    }

    public function boundaries(string $model): array
    {
        return [new Boundary($this->column, $this->duration)];
    }

    public function describe(): string
    {
        return "{$this->column} older than {$this->duration->forHumans()}";
    }
}
