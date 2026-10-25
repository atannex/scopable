<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Support\Clock;
use Atannex\Scopable\Support\Values;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * column <op> value. The value may be a Closure receiving "now", for moving
 * thresholds. NULL columns never pass, and always fall in the inverse.
 */
final class Compare extends Rule
{
    public const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>='];

    public function __construct(
        public readonly string $column,
        public readonly string $operator,
        public readonly mixed $value,
    ) {
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException("Unsupported operator [{$operator}]; use one of ".implode(' ', self::OPERATORS).'.');
        }
    }

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->where($this->qualify($query, $this->column), $this->operator, $this->bound($now));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q
            ->where($column, Values::inverseOperator($this->operator), $this->bound($now))
            ->orWhereNull($column));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        return Values::compare($model->getAttribute($this->column), $this->operator, $this->resolve($now));
    }

    public function describe(): string
    {
        $value = $this->value instanceof Closure ? '(computed)' : Values::describe($this->value);

        return "{$this->column} {$this->operator} {$value}";
    }

    private function resolve(CarbonImmutable $now): mixed
    {
        return Values::normalize($this->value instanceof Closure ? ($this->value)($now) : $this->value);
    }

    private function bound(CarbonImmutable $now): mixed
    {
        $value = $this->resolve($now);

        return $value instanceof DateTimeInterface ? Clock::bind(CarbonImmutable::instance($value)) : $value;
    }
}
