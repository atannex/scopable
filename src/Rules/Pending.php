<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Exceptions\CannotChangeState;
use Atannex\Scopable\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A date that is set and still ahead: scheduled (published_at > now),
 * upcoming (starts_at > now).
 */
final class Pending extends Rule
{
    public function __construct(public readonly string $column) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->where($this->qualify($query, $this->column), '>', Clock::bind($now));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $column = $this->qualify($query, $this->column);

        $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, '<=', Clock::bind($now)));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $date = Clock::value($model->getAttribute($this->column));

        return $date !== null && $date->greaterThan($now);
    }

    public function boundaries(string $model): array
    {
        return [new Boundary($this->column)];
    }

    public function canChange(bool $on): bool
    {
        return true;
    }

    /**
     * On needs a future $from. Off clears the date.
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if (! $on) {
            $model->setAttribute($this->column, null);

            return;
        }

        if ($from === null || $from->lessThanOrEqualTo($now)) {
            throw CannotChangeState::because($this->describe(), 'give a moment in the future.');
        }

        $model->setAttribute($this->column, $from);
    }

    public function describe(): string
    {
        return "{$this->column} is set and > now";
    }
}
