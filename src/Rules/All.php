<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every rule passes. The inverse is "any rule fails" (De Morgan).
 */
final class All extends Rule
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        foreach ($this->rules as $rule) {
            $query->where(fn (Builder $q) => $rule->apply($q, $now));
        }
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        if ($this->rules === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($now): void {
            foreach ($this->rules as $rule) {
                $q->orWhere(fn (Builder $q) => $rule->applyInverse($q, $now));
            }
        });
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        foreach ($this->rules as $rule) {
            if (! $rule->passes($model, $now)) {
                return false;
            }
        }

        return true;
    }

    public function boundaries(string $model): array
    {
        return array_merge([], ...array_map(fn (Rule $rule) => $rule->boundaries($model), $this->rules));
    }

    public function canChange(bool $on): bool
    {
        $changeable = array_filter($this->rules, fn (Rule $rule) => $rule->canChange($on));

        return $on ? count($changeable) === count($this->rules) : $changeable !== [];
    }

    /**
     * On: every rule is entered. Off: the first rule that can be left is left
     * (for a flag + window, the flag is switched off and the dates are kept).
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if ($on) {
            foreach ($this->rules as $rule) {
                $rule->change($model, true, $now, $from, $until);
            }

            return;
        }

        foreach ($this->rules as $rule) {
            if ($rule->canChange(false)) {
                $rule->change($model, false, $now, $from, $until);

                return;
            }
        }

        parent::change($model, $on, $now, $from, $until);
    }

    public function describe(): string
    {
        return $this->rules === [] ? 'always' : implode(' and ', array_map(fn (Rule $rule) => self::wrap($rule), $this->rules));
    }

    private static function wrap(Rule $rule): string
    {
        return $rule instanceof Any ? '('.$rule->describe().')' : $rule->describe();
    }
}
