<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A column is NULL (a draft has no published_at).
 */
final class Missing extends Rule
{
    public function __construct(public readonly string $column) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->whereNull($this->qualify($query, $this->column));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $query->whereNotNull($this->qualify($query, $this->column));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $value = $model->getAttribute($this->column);

        return $value === null || $value === '';
    }

    public function canChange(bool $on): bool
    {
        return $on;
    }

    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if (! $on) {
            parent::change($model, $on, $now, $from, $until);
        }

        $model->setAttribute($this->column, null);
    }

    public function describe(): string
    {
        return "{$this->column} is null";
    }
}
