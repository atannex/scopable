<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Exceptions\CannotChangeState;
use Atannex\Scopable\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Now lies in [from, until): a campaign that is running, a notice still on,
 * a token not yet expired. A NULL bound is open ("from the start", "no end")
 * unless it is required, then NULL means "not running".
 */
final class Window extends Rule
{
    public function __construct(
        public readonly ?string $from = 'starts_at',
        public readonly ?string $until = 'ends_at',
        public readonly bool $fromRequired = false,
        public readonly bool $untilRequired = false,
    ) {
        if ($from === null && $until === null) {
            throw new InvalidArgumentException('A window needs a start column, an end column, or both.');
        }
    }

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $at = Clock::bind($now);

        if ($this->from !== null) {
            $from = $this->qualify($query, $this->from);

            $this->fromRequired
                ? $query->where($from, '<=', $at)
                : $query->where(fn (Builder $q) => $q->whereNull($from)->orWhere($from, '<=', $at));
        }

        if ($this->until !== null) {
            $until = $this->qualify($query, $this->until);

            $this->untilRequired
                ? $query->where($until, '>', $at)
                : $query->where(fn (Builder $q) => $q->whereNull($until)->orWhere($until, '>', $at));
        }
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $at = Clock::bind($now);

        $query->where(function (Builder $q) use ($at): void {
            if ($this->from !== null) {
                $from = $this->qualify($q, $this->from);

                $this->fromRequired
                    ? $q->orWhere(fn (Builder $q) => $q->whereNull($from)->orWhere($from, '>', $at))
                    : $q->orWhere($from, '>', $at);
            }

            if ($this->until !== null) {
                $until = $this->qualify($q, $this->until);

                $this->untilRequired
                    ? $q->orWhere(fn (Builder $q) => $q->whereNull($until)->orWhere($until, '<=', $at))
                    : $q->orWhere($until, '<=', $at);
            }
        });
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        return $this->started($model, $now) && $this->notEnded($model, $now);
    }

    public function started(Model $model, CarbonImmutable $now): bool
    {
        if ($this->from === null) {
            return true;
        }

        $from = Clock::value($model->getAttribute($this->from));

        return $from === null ? ! $this->fromRequired : $from->lessThanOrEqualTo($now);
    }

    public function notEnded(Model $model, CarbonImmutable $now): bool
    {
        if ($this->until === null) {
            return true;
        }

        $until = Clock::value($model->getAttribute($this->until));

        return $until === null ? ! $this->untilRequired : $until->greaterThan($now);
    }

    public function boundaries(string $model): array
    {
        return array_values(array_map(
            fn (string $column) => new Boundary($column),
            array_filter([$this->from, $this->until]),
        ));
    }

    public function canChange(bool $on): bool
    {
        return $on ? true : ($this->until !== null || $this->fromRequired);
    }

    /**
     * On: start now (or at $from) unless already started, and end at $until,
     * reopening an ended window. A future $from schedules it.
     * Off: end it now; a start-only window clears its required start.
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        if (! $on) {
            match (true) {
                $this->until !== null => $model->setAttribute($this->until, $now),
                $this->fromRequired => $model->setAttribute($this->from, null),
                default => throw CannotChangeState::because($this->describe(), 'a window with an optional start and no end cannot be closed.'),
            };

            return;
        }

        if ($this->from !== null && ($from !== null || ! $this->started($model, $now))) {
            $model->setAttribute($this->from, $from ?? $now);
        }

        if ($this->until !== null) {
            if ($until !== null) {
                $model->setAttribute($this->until, $until);
            } elseif (! $this->notEnded($model, $now)) {
                if ($this->untilRequired) {
                    throw CannotChangeState::because($this->describe(), "give an end ({$this->until}) after now.");
                }

                $model->setAttribute($this->until, null);
            }
        }
    }

    public function describe(): string
    {
        $parts = [];

        if ($this->from !== null) {
            $parts[] = $this->fromRequired ? "{$this->from} <= now" : "({$this->from} is null or <= now)";
        }

        if ($this->until !== null) {
            $parts[] = $this->untilRequired ? "{$this->until} > now" : "({$this->until} is null or > now)";
        }

        return implode(' and ', $parts);
    }
}
