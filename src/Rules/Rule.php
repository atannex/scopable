<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Exceptions\CannotChangeState;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One condition, four ways:
 *
 * - apply():        constrain a query to rows that satisfy it;
 * - applyInverse(): constrain a query to every other row, NULLs included,
 *                   so apply + applyInverse always split a table in two;
 * - passes():       the same check on a loaded model, without a query;
 * - change():       set attributes so the model enters or leaves it.
 *
 * Rules run inside their own where-group, so an OR never leaks out.
 * The static constructors below are the public way to build them.
 */
abstract class Rule
{
    abstract public function apply(Builder $query, CarbonImmutable $now): void;

    abstract public function applyInverse(Builder $query, CarbonImmutable $now): void;

    abstract public function passes(Model $model, CarbonImmutable $now): bool;

    /**
     * Human-readable, for scopable:show and error messages.
     */
    abstract public function describe(): string;

    /**
     * Columns whose value, once passed by the clock, can flip this rule
     * without a save (used by scopable:sweep).
     *
     * @param  class-string<Model>  $model
     * @return list<Boundary>
     */
    public function boundaries(string $model): array
    {
        return [];
    }

    /**
     * Whether change() can make the rule pass ($on) or fail (! $on).
     */
    public function canChange(bool $on): bool
    {
        return false;
    }

    /**
     * Set attributes (without saving) so the model passes ($on) or fails.
     * $from / $until are optional moments for time-based rules.
     */
    public function change(Model $model, bool $on, CarbonImmutable $now, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): void
    {
        throw CannotChangeState::because($this->describe(), 'it has no attribute that can be set directly.');
    }

    /*
    |--------------------------------------------------------------------------
    | Constructors
    |--------------------------------------------------------------------------
    */

    /** A boolean column holds $value (is_active, is_featured, is_global…). */
    public static function toggle(string $column, bool $value = true): Toggle
    {
        return new Toggle($column, $value);
    }

    /** A date column is set and has passed (published_at <= now). */
    public static function reached(string $column): Reached
    {
        return new Reached($column);
    }

    /** A date column is set and still ahead (scheduled: published_at > now). */
    public static function pending(string $column): Pending
    {
        return new Pending($column);
    }

    /** A column is NULL (a draft: published_at IS NULL). */
    public static function missing(string $column): Missing
    {
        return new Missing($column);
    }

    /** A column is NOT NULL. */
    public static function present(string $column): Not
    {
        return new Not(new Missing($column));
    }

    /**
     * Now lies in [from, until). A NULL bound is open unless required.
     */
    public static function window(?string $from = 'starts_at', ?string $until = 'ends_at', bool $fromRequired = false, bool $untilRequired = false): Window
    {
        return new Window($from, $until, $fromRequired, $untilRequired);
    }

    /** A start date still ahead (an upcoming event or campaign). */
    public static function upcoming(string $from = 'starts_at'): Pending
    {
        return new Pending($from);
    }

    /** An end date that has passed (an ended campaign, an expired token). */
    public static function ended(string $until = 'ends_at'): Reached
    {
        return new Reached($until);
    }

    /**
     * A column equals one of $values. $otherwise is written when the state is left.
     */
    public static function equals(string $column, mixed $values, mixed $otherwise = null): Equals
    {
        return new Equals($column, $values, $otherwise);
    }

    public static function in(string $column, array $values, mixed $otherwise = null): Equals
    {
        return new Equals($column, $values, $otherwise);
    }

    /** column <operator> value; $value may be a Closure(CarbonImmutable $now). */
    public static function compare(string $column, string $operator, mixed $value): Compare
    {
        return new Compare($column, $operator, $value);
    }

    /** $used is below $cap, or $cap is NULL (unlimited). impressions < max_impressions. */
    public static function under(string $used, string $cap): Under
    {
        return new Under($used, $cap);
    }

    /** A date in the last $duration (fresh: created_at within 7 days). */
    public static function within(string $column, mixed $duration): Within
    {
        return new Within($column, $duration);
    }

    /** A date older than $duration (stale: updated_at more than a year ago). */
    public static function olderThan(string $column, mixed $duration): OlderThan
    {
        return new OlderThan($column, $duration);
    }

    public static function all(Rule ...$rules): All
    {
        return new All(array_values($rules));
    }

    public static function any(Rule ...$rules): Any
    {
        return new Any(array_values($rules));
    }

    public static function not(Rule $rule): Not
    {
        return new Not($rule);
    }

    /** Another state of the same model, by name (or inverse/alias name). */
    public static function state(string $name): StateReference
    {
        return new StateReference($name);
    }

    /** A related row exists (optionally in one of its own states). */
    public static function related(string $relation, ?string $state = null): Related
    {
        return new Related($relation, $state);
    }

    /**
     * Anything else. Without $passes, model checks run a query by key; without
     * $inverse, the inverse is NOT (…), which drops rows where it is NULL.
     *
     * @param  Closure(Builder, CarbonImmutable): mixed  $query
     * @param  (Closure(Model, CarbonImmutable): bool)|null  $passes
     * @param  (Closure(Builder, CarbonImmutable): mixed)|null  $inverse
     */
    public static function callback(Closure $query, ?Closure $passes = null, ?Closure $inverse = null, string $description = 'custom condition'): Callback
    {
        return new Callback($query, $passes, $inverse, $description);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function qualify(Builder $query, string $column): string
    {
        return $query->qualifyColumn($column);
    }
}
