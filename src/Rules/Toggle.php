<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A boolean column. NULL counts as "not $value" on both sides.
 */
final class Toggle extends Rule
{
    public function __construct(
        public readonly string $column,
        public readonly bool $value = true,
    ) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->where($this->qualify($query, $this->column), $this->value);
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q->where($column, '!=', $this->value)->orWhereNull($column));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $value = $model->getAttribute($this->column);

        return $value !== null && (bool) $value === $this->value;
    }

    public function canChange(bool $on): bool
    {
        return true;
    }

    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        $model->setAttribute($this->column, $on ? $this->value : ! $this->value);
    }

    public function describe(): string
    {
        return $this->column.' is '.($this->value ? 'true' : 'false');
    }
}
