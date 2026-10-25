<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Exceptions\InvalidDefinition;
use Atannex\Scopable\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Another state of the same model, so states can build on each other
 * ("featured" = published and is_featured). Loops are refused.
 */
final class StateReference extends Rule
{
    /** @var array<string, true> */
    private static array $resolving = [];

    public function __construct(public readonly string $name) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $this->guard($query->getModel(), fn (Rule $rule, bool $positive) => $positive ? $rule->apply($query, $now) : $rule->applyInverse($query, $now));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $this->guard($query->getModel(), fn (Rule $rule, bool $positive) => $positive ? $rule->applyInverse($query, $now) : $rule->apply($query, $now));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        return $this->guard($model, fn (Rule $rule, bool $positive) => $rule->passes($model, $now) === $positive);
    }

    public function boundaries(string $model): array
    {
        [$state] = Registry::for($model)->find($this->name);

        return $state->rule->boundaries($model);
    }

    public function canChange(bool $on): bool
    {
        return true;
    }

    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        $this->guard($model, fn (Rule $rule, bool $positive) => $rule->change($model, $on === $positive, $now, $from, $until));
    }

    public function describe(): string
    {
        return "state {$this->name}";
    }

    /**
     * @template T
     *
     * @param  callable(Rule, bool): T  $callback
     * @return T
     */
    private function guard(Model $model, callable $callback): mixed
    {
        $key = $model::class.'@'.$this->name;

        if (isset(self::$resolving[$key])) {
            throw InvalidDefinition::cycle($model::class, [...array_keys(self::$resolving), $key]);
        }

        [$state, $positive] = Registry::for($model::class)->find($this->name);

        self::$resolving[$key] = true;

        try {
            return $callback($state->rule, $positive);
        } finally {
            unset(self::$resolving[$key]);
        }
    }
}
