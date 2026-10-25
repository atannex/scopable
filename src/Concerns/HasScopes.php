<?php

declare(strict_types=1);

namespace Atannex\Scopable\Concerns;

use Atannex\Scopable\DefaultState;
use Atannex\Scopable\Period;
use Atannex\Scopable\Registry;
use Atannex\Scopable\Scopes;
use Atannex\Scopable\Support\Clock;
use Atannex\Scopable\Support\Tracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Declarative state and date scopes. Declare them once:
 *
 *     public static function defineScopes(Scopes $scopes): void
 *     {
 *         $scopes->published();                          // published / scheduled / draft / unpublished
 *         $scopes->toggle('featured');                   // is_featured
 *         $scopes->highlight('breaking', 'is_breaking', 'breaking_at', 'breaking_expires');
 *         $scopes->sortable('order');
 *     }
 *
 * and use them on queries (Post::published(), ->notFeatured(),
 * ->createdThisWeek(), ->publishedWithin('7 days'), ->ordered()) and on
 * models ($post->inState('breaking'), $post->enterState('featured')).
 *
 * @mixin Model
 */
trait HasScopes
{
    public static function bootHasScopes(): void
    {
        $scopes = static::scopeDefinitions();

        foreach ($scopes->defaults() as $state) {
            static::addGlobalScope(DefaultState::identifier($state->name), new DefaultState($state->name));
        }

        if ($scopes->tracked() !== []) {
            static::saved(fn (self $model) => app(Tracker::class)->saved($model));
        }
    }

    /**
     * Declare the model's states, dates, filters and sorts. Override this.
     */
    public static function defineScopes(Scopes $scopes): void {}

    public static function scopeDefinitions(): Scopes
    {
        return Registry::for(static::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Named scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Declared states, filters, sorts and date periods count as named scopes,
     * so they work statically, on relations, in ->scopes([...]) and wherever
     * Eloquent checks for a scope.
     *
     * @param  string  $scope
     * @return bool
     */
    public function hasNamedScope($scope)
    {
        return parent::hasNamedScope($scope) || static::scopeDefinitions()->resolve($scope) !== null;
    }

    /**
     * @param  string  $scope
     * @return mixed
     */
    public function callNamedScope($scope, array $parameters = [])
    {
        if (parent::hasNamedScope($scope)) {
            return parent::callNamedScope($scope, $parameters);
        }

        return static::scopeDefinitions()->resolve($scope)(...$parameters);
    }

    /**
     * ->whereState('published'), ->whereState(['published', 'featured']),
     * ->whereState('breaking', false), ->whereState('published', at: $moment).
     *
     * @param  string|list<string>  $states
     */
    public function scopeWhereState(Builder $query, string|array $states, bool $active = true, mixed $at = null): Builder
    {
        foreach ((array) $states as $state) {
            static::scopeDefinitions()->apply($query, $state, $active, $at);
        }

        return $query;
    }

    /**
     * Rows in none of the given states.
     *
     * @param  string|list<string>  $states
     */
    public function scopeWhereNotState(Builder $query, string|array $states, mixed $at = null): Builder
    {
        return $this->scopeWhereState($query, $states, false, $at);
    }

    /**
     * ->published()->orWhereState('scheduled').
     *
     * @param  string|list<string>  $states
     */
    public function scopeOrWhereState(Builder $query, string|array $states, bool $active = true, mixed $at = null): Builder
    {
        return $query->orWhere(fn (Builder $q) => $this->scopeWhereState($q, $states, $active, $at));
    }

    /**
     * Rows in at least one of the given states.
     *
     * @param  list<string>  $states
     */
    public function scopeWhereAnyState(Builder $query, array $states, mixed $at = null): Builder
    {
        return $query->where(function (Builder $q) use ($states, $at): void {
            foreach ($states as $state) {
                $q->orWhere(fn (Builder $q) => static::scopeDefinitions()->apply($q, $state, true, $at));
            }
        });
    }

    /**
     * ->wherePeriod('created', 'last 7 days'), ->wherePeriod('starts_at', Period::nextWeek()).
     */
    public function scopeWherePeriod(Builder $query, string $date, Period|string $period): Builder
    {
        return static::scopeDefinitions()->applyPeriod($query, $date, $period);
    }

    /**
     * Lift default states: all of them, or the ones named.
     */
    public function scopeWithoutStateDefaults(Builder $query, string ...$states): Builder
    {
        $states = $states !== [] ? $states : array_map(fn ($state) => $state->name, static::scopeDefinitions()->defaults());

        return $query->withoutGlobalScopes(array_map(DefaultState::identifier(...), $states));
    }

    /**
     * Rows per state in one query: Comment::countStates(['pending', 'approved'])
     * gives ['pending' => 3, 'approved' => 120]. Pass a query to count within it.
     *
     * @param  list<string>  $states
     * @return array<string, int>
     */
    public static function countStates(array $states, ?Builder $query = null, mixed $at = null): array
    {
        return static::scopeDefinitions()->count($query ?? static::query(), $states, $at);
    }

    /*
    |--------------------------------------------------------------------------
    | Model checks
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this model is in a state (or, for an inverse name, outside it),
     * now or at $at. Uses the loaded attributes, no query.
     */
    public function inState(string $state, mixed $at = null): bool
    {
        return static::scopeDefinitions()->passes($this, $state, $at);
    }

    /**
     * Every declared state => whether the model is in it.
     *
     * @return array<string, bool>
     */
    public function stateMap(mixed $at = null): array
    {
        $now = Clock::at($at);

        return array_map(fn ($state) => $state->rule->passes($this, $now), static::scopeDefinitions()->states());
    }

    /**
     * Whether a date alias (or column) falls in a period.
     */
    public function inPeriod(string $date, Period|string $period): bool
    {
        $column = static::scopeDefinitions()->dates()[$date] ?? $date;

        return Period::parse($period)->contains($this->getAttribute($column));
    }

    /*
    |--------------------------------------------------------------------------
    | Changes
    |--------------------------------------------------------------------------
    */

    /**
     * Set the attributes that put the model in ($on) or out of a state,
     * without saving. $from / $until are used by time-based states.
     */
    public function setState(string $state, bool $on = true, mixed $from = null, mixed $until = null): static
    {
        [$definition, $positive] = static::scopeDefinitions()->find($state);

        $definition->rule->change(
            $this,
            $on === $positive,
            Clock::now(),
            $from === null ? null : Clock::at($from),
            $until === null ? null : Clock::at($until),
        );

        return $this;
    }

    /**
     * Enter a state and save: $post->enterState('breaking', until: now()->addHours(6)).
     */
    public function enterState(string $state, mixed $from = null, mixed $until = null): bool
    {
        return $this->setState($state, true, $from, $until)->save();
    }

    /**
     * Leave a state and save.
     */
    public function leaveState(string $state): bool
    {
        return $this->setState($state, false)->save();
    }

    /**
     * Enter a state at a later moment (and optionally leave it at $until), and save.
     */
    public function scheduleState(string $state, mixed $from, mixed $until = null): bool
    {
        return $this->setState($state, true, $from, $until)->save();
    }
}
