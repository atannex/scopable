<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Exceptions\CannotChangeState;
use Atannex\Scopable\Support\Values;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A column holds one of a set of values (status = 'active',
 * flag IN ('published', 'featured')). Enum casts compare by value.
 */
final class Equals extends Rule
{
    /** @var list<mixed> */
    public readonly array $values;

    public readonly mixed $otherwise;

    public function __construct(public readonly string $column, mixed $values, mixed $otherwise = null)
    {
        $this->values = Values::list($values);
        $this->otherwise = Values::normalize($otherwise);

        if ($this->values === [] || in_array(null, $this->values, true)) {
            throw new InvalidArgumentException("Equals on [{$column}] needs non-null values; use Rule::missing() for NULL.");
        }
    }

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        count($this->values) === 1
            ? $query->where($column, $this->values[0])
            : $query->whereIn($column, $this->values);
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q->whereNotIn($column, $this->values)->orWhereNull($column));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $value = $model->getAttribute($this->column);

        foreach ($this->values as $candidate) {
            if (Values::same($value, $candidate)) {
                return true;
            }
        }

        return false;
    }

    public function canChange(bool $on): bool
    {
        return $on || $this->otherwise !== null;
    }

    /**
     * On writes the first value (unless one already matches); off writes $otherwise.
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if ($on) {
            if (! $this->passes($model, $now)) {
                $model->setAttribute($this->column, $this->values[0]);
            }

            return;
        }

        if ($this->otherwise === null) {
            throw CannotChangeState::because($this->describe(), 'no "otherwise" value was given to leave it.');
        }

        if ($this->passes($model, $now)) {
            $model->setAttribute($this->column, $this->otherwise);
        }
    }

    public function describe(): string
    {
        return count($this->values) === 1
            ? "{$this->column} = ".Values::describe($this->values[0])
            : "{$this->column} in ".Values::describe($this->values);
    }
}
