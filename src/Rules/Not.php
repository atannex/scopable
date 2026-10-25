<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The opposite of a rule, using its own NULL-aware inverse.
 */
final class Not extends Rule
{
    public function __construct(public readonly Rule $rule) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $this->rule->applyInverse($query, $now);
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $this->rule->apply($query, $now);
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        return ! $this->rule->passes($model, $now);
    }

    public function boundaries(string $model): array
    {
        return $this->rule->boundaries($model);
    }

    public function canChange(bool $on): bool
    {
        return $this->rule->canChange(! $on);
    }

    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        $this->rule->change($model, ! $on, $now, $from, $until);
    }

    public function describe(): string
    {
        return 'not ('.$this->rule->describe().')';
    }
}
