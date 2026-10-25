<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * At least one rule passes. The inverse is "every rule fails".
 */
final class Any extends Rule
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        if ($this->rules === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($now): void {
            foreach ($this->rules as $rule) {
                $q->orWhere(fn (Builder $q) => $rule->apply($q, $now));
            }
        });
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        foreach ($this->rules as $rule) {
            $query->where(fn (Builder $q) => $rule->applyInverse($q, $now));
        }
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        foreach ($this->rules as $rule) {
            if ($rule->passes($model, $now)) {
                return true;
            }
        }

        return false;
    }

    public function boundaries(string $model): array
    {
        return array_merge([], ...array_map(fn (Rule $rule) => $rule->boundaries($model), $this->rules));
    }

    public function canChange(bool $on): bool
    {
        $changeable = array_filter($this->rules, fn (Rule $rule) => $rule->canChange($on));

        return $on ? $changeable !== [] : count($changeable) === count($this->rules);
    }

    /**
     * On: the first rule that can be entered is entered. Off: every rule is left.
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if (! $on) {
            foreach ($this->rules as $rule) {
                $rule->change($model, false, $now, $from, $until);
            }

            return;
        }

        if ($this->passes($model, $now)) {
            return;
        }

        foreach ($this->rules as $rule) {
            if ($rule->canChange(true)) {
                $rule->change($model, true, $now, $from, $until);

                return;
            }
        }

        parent::change($model, $on, $now, $from, $until);
    }

    public function describe(): string
    {
        return $this->rules === [] ? 'never' : implode(' or ', array_map(fn (Rule $rule) => $rule instanceof All ? '('.$rule->describe().')' : $rule->describe(), $this->rules));
    }
}
