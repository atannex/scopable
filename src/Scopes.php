<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Atannex\Scopable\Exceptions\InvalidDefinition;
use Atannex\Scopable\Exceptions\UnknownState;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Support\Clock;
use Atannex\Scopable\Support\Values;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Everything a model declares in defineScopes(): states, date aliases,
 * value filters and sort orders. Once sealed, each name compiles to a named
 * scope (Post::published(), ->notBreaking(), ->createdThisWeek()).
 */
final class Scopes
{
    /**
     * Period scopes generated for every date alias: {alias}{Suffix}.
     *
     * @var array<string, string>
     */
    public const DATE_SUFFIXES = [
        'Today' => 'today',
        'Yesterday' => 'yesterday',
        'Tomorrow' => 'tomorrow',
        'ThisWeek' => 'thisWeek',
        'LastWeek' => 'lastWeek',
        'NextWeek' => 'nextWeek',
        'ThisMonth' => 'thisMonth',
        'LastMonth' => 'lastMonth',
        'NextMonth' => 'nextMonth',
        'ThisQuarter' => 'thisQuarter',
        'LastQuarter' => 'lastQuarter',
        'NextQuarter' => 'nextQuarter',
        'ThisYear' => 'thisYear',
        'LastYear' => 'lastYear',
        'NextYear' => 'nextYear',
        'InPast' => 'past',
        'InFuture' => 'future',
        'Between' => 'between',
        'After' => 'after',
        'Since' => 'since',
        'Before' => 'before',
        'Until' => 'until',
        'On' => 'on',
        'Within' => 'within',
        'OlderThan' => 'olderThan',
        'InNext' => 'inNext',
        'InYear' => 'inYear',
        'InMonth' => 'inMonth',
        'In' => 'parse',
    ];

    /** @var array<string, State> */
    private array $states = [];

    /** @var array<string, string> alias => column */
    private array $dates = [];

    /** @var array<string, string> name => column */
    private array $filters = [];

    /** @var array<string, list<array{0: string, 1: string}>> name => [[column, direction], …] */
    private array $sorters = [];

    /** @var array<string, array{0: State, 1: bool}> every state name, inverse and alias */
    private array $lookup = [];

    /** @var array<string, Closure> compiled named scopes */
    private array $methods = [];

    /** @var array<string, Closure|null> memoised date-method lookups */
    private array $resolved = [];

    private bool $sealed = false;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(public readonly string $model) {}

    /*
    |--------------------------------------------------------------------------
    | Declaring states
    |--------------------------------------------------------------------------
    */

    /**
     * Any rule under a name.
     */
    public function state(string $name, Rule $rule): State
    {
        $this->assertOpen();

        return $this->states[$name] = new State($name, $rule);
    }

    /**
     * A boolean column: toggle('active') reads is_active; ->active(), ->notActive().
     */
    public function toggle(string $name, ?string $column = null, ?string $inverse = null): State
    {
        $state = $this->state($name, Rule::toggle($column ?? 'is_'.Str::snake($name)));

        return $inverse !== null ? $state->inverse($inverse) : $state;
    }

    /**
     * A moment that, once reached, puts the model in the state, plus the
     * optional "set but ahead" and "not set" states and a date alias.
     */
    public function timestamp(string $name, string $column, ?string $pending = null, ?string $missing = null, ?string $inverse = null, string|false|null $date = null): State
    {
        $state = $this->state($name, Rule::reached($column));

        if ($inverse !== null) {
            $state->inverse($inverse);
        }

        if ($pending !== null) {
            $this->state($pending, Rule::pending($column));
        }

        if ($missing !== null) {
            $this->state($missing, Rule::missing($column));
        }

        if ($date !== false) {
            $this->date($date ?? $name, $column);
        }

        return $state;
    }

    /**
     * published_at: published (set, passed), scheduled (set, ahead), draft
     * (null), unpublished (scheduled or draft), and publishedThisWeek()…
     */
    public function published(string $column = 'published_at', string $name = 'published', ?string $scheduled = 'scheduled', ?string $draft = 'draft', ?string $inverse = 'unpublished'): State
    {
        return $this->timestamp($name, $column, $scheduled, $draft, $inverse);
    }

    /**
     * A schedule window [from, until): ->running(); optional upcoming
     * (start ahead) and ended (end passed) states.
     */
    public function window(string $name, ?string $from = 'starts_at', ?string $until = 'ends_at', ?string $upcoming = null, ?string $ended = null, bool $fromRequired = false, bool $untilRequired = false, ?string $inverse = null): State
    {
        $state = $this->state($name, Rule::window($from, $until, $fromRequired, $untilRequired));

        if ($inverse !== null) {
            $state->inverse($inverse);
        }

        if ($upcoming !== null) {
            $from ?? throw new InvalidArgumentException("Window [{$name}] needs a start column for [{$upcoming}].");
            $this->state($upcoming, Rule::upcoming($from));
        }

        if ($ended !== null) {
            $until ?? throw new InvalidArgumentException("Window [{$name}] needs an end column for [{$ended}].");
            $this->state($ended, Rule::ended($until));
        }

        return $state;
    }

    /**
     * expires_at: ->expired() (passed), ->unexpired() (null or ahead), and
     * expiresInNext('7 days') for "expiring soon".
     */
    public function expires(string $column = 'expires_at', string $name = 'expired', ?string $inverse = 'unexpired', string|false $date = 'expires'): State
    {
        return $this->timestamp($name, $column, inverse: $inverse, date: $date);
    }

    /**
     * A flag that only counts inside its window: is_breaking with
     * breaking_at (required, reached) and breaking_expires (optional, ahead).
     */
    public function highlight(string $name, string $flag, string $from, ?string $until = null, ?string $inverse = null): State
    {
        $state = $this->state($name, Rule::all(Rule::toggle($flag), Rule::window($from, $until, fromRequired: true)));

        return $inverse !== null ? $state->inverse($inverse) : $state;
    }

    /**
     * A column equal to one of $values (status = 'approved'). $otherwise is
     * written when the state is left through leaveState().
     */
    public function equals(string $name, string $column, mixed $values, mixed $otherwise = null, ?string $inverse = null): State
    {
        $state = $this->state($name, Rule::equals($column, $values, $otherwise));

        return $inverse !== null ? $state->inverse($inverse) : $state;
    }

    /*
    |--------------------------------------------------------------------------
    | Declaring dates, filters and sorts
    |--------------------------------------------------------------------------
    */

    /**
     * Period scopes for a column: date('published', 'published_at') gives
     * publishedToday(), publishedWithin('7 days'), publishedIn('last month')…
     */
    public function date(string $alias, string $column): self
    {
        $this->assertOpen();
        $this->dates[$alias] = $column;

        return $this;
    }

    /**
     * A value filter: filter('flagged', 'flag') gives ->flagged('a', 'b')
     * and ->notFlagged('a') (which keeps NULLs).
     */
    public function filter(string $name, string $column): self
    {
        $this->assertOpen();
        $this->filters[$name] = $column;

        return $this;
    }

    /**
     * A sort order: sortable('order') gives ->ordered() and ->ordered('desc').
     * $then adds tie-breakers: ['created_at' => 'desc'].
     *
     * @param  array<string, string>  $then
     */
    public function sortable(string $column, string $name = 'ordered', string $direction = 'asc', array $then = []): self
    {
        $this->assertOpen();

        $columns = [[$column, $direction]];

        foreach ($then as $tie => $tieDirection) {
            $columns[] = [$tie, $tieDirection];
        }

        $this->sorters[$name] = $columns;

        return $this;
    }

    /**
     * created / updated / deleted, when the model has them and config allows.
     *
     * @internal
     */
    public function registerDefaultDates(Model $prototype): void
    {
        $enabled = (array) config('scopable.dates', ['created' => true, 'updated' => true, 'deleted' => true]);

        if ($prototype->usesTimestamps()) {
            if (($enabled['created'] ?? true) && $prototype->getCreatedAtColumn() !== null) {
                $this->dates['created'] = $prototype->getCreatedAtColumn();
            }

            if (($enabled['updated'] ?? true) && $prototype->getUpdatedAtColumn() !== null) {
                $this->dates['updated'] = $prototype->getUpdatedAtColumn();
            }
        }

        if (($enabled['deleted'] ?? true) && method_exists($prototype, 'getDeletedAtColumn')) {
            $this->dates['deleted'] = $prototype->getDeletedAtColumn();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /** @return array<string, State> */
    public function states(): array
    {
        return $this->states;
    }

    public function has(string $name): bool
    {
        return isset($this->lookup[$name]);
    }

    /**
     * A state by name, inverse or alias, and whether the name means the state
     * itself (true) or its opposite (false).
     *
     * @return array{0: State, 1: bool}
     */
    public function find(string $name): array
    {
        return $this->lookup[$name] ?? throw UnknownState::named($this->model, $name, array_keys($this->states));
    }

    /** @return array<string, string> */
    public function dates(): array
    {
        return $this->dates;
    }

    public function dateColumn(string $alias): string
    {
        return $this->dates[$alias] ?? throw UnknownState::date($this->model, $alias);
    }

    /** @return array<string, string> */
    public function filters(): array
    {
        return $this->filters;
    }

    /** @return array<string, list<array{0: string, 1: string}>> */
    public function sorters(): array
    {
        return $this->sorters;
    }

    /** @return list<State> */
    public function defaults(): array
    {
        return array_values(array_filter($this->states, fn (State $state) => $state->isDefault()));
    }

    /** @return list<State> */
    public function tracked(): array
    {
        return array_values(array_filter($this->states, fn (State $state) => $state->isTracked()));
    }

    /**
     * Every named scope this definition adds (date scopes as {alias}{Suffix}).
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return array_keys($this->methods);
    }

    /*
    |--------------------------------------------------------------------------
    | Using
    |--------------------------------------------------------------------------
    */

    /**
     * The named scope for a method name, if this definition has one.
     */
    public function resolve(string $method): ?Closure
    {
        if (isset($this->methods[$method])) {
            return $this->methods[$method];
        }

        if (array_key_exists($method, $this->resolved)) {
            return $this->resolved[$method];
        }

        return $this->resolved[$method] = $this->resolveDateMethod($method);
    }

    /**
     * Constrain a query to a state ($active) or its opposite.
     */
    public function apply(Builder $query, string $name, bool $active = true, mixed $at = null): Builder
    {
        [$state, $positive] = $this->find($name);
        $now = $at instanceof CarbonImmutable ? $at : Clock::at($at);

        $query->where(fn (Builder $q) => $active === $positive
            ? $state->rule->apply($q, $now)
            : $state->rule->applyInverse($q, $now));

        return $query;
    }

    /**
     * Check a loaded model against a state, without a query.
     */
    public function passes(Model $model, string $name, mixed $at = null): bool
    {
        [$state, $positive] = $this->find($name);
        $now = $at instanceof CarbonImmutable ? $at : Clock::at($at);

        return $state->rule->passes($model, $now) === $positive;
    }

    /**
     * How many rows are in each state, in one query:
     * SUM(CASE WHEN <state> THEN 1 ELSE 0 END) per state.
     *
     * @param  list<string>  $states
     * @return array<string, int>
     */
    public function count(Builder $query, array $states, mixed $at = null): array
    {
        if ($states === []) {
            return [];
        }

        $now = $at instanceof CarbonImmutable ? $at : Clock::at($at);
        $grammar = $query->getQuery()->getGrammar();
        $select = $query->clone()->reorder()->select([]);
        $columns = [];

        foreach (array_values($states) as $index => $state) {
            $condition = $query->getModel()->newQueryWithoutScopes();
            $this->apply($condition, $state, true, $now);

            $base = $condition->getQuery();
            $sql = preg_replace('/^where /', '', $grammar->compileWheres($base));
            $columns["s{$index}"] = $state;

            $select->selectRaw("SUM(CASE WHEN {$sql} THEN 1 ELSE 0 END) as s{$index}", $base->getBindings());
        }

        $row = (array) ($select->toBase()->first() ?? []);

        return array_combine($columns, array_map(fn (string $alias) => (int) ($row[$alias] ?? 0), array_keys($columns)));
    }

    /**
     * Constrain a query to a period of a date alias (or a raw column).
     */
    public function applyPeriod(Builder $query, string $date, Period|string $period): Builder
    {
        $column = $this->dates[$date] ?? $date;

        return Period::parse($period)->apply($query, $column);
    }

    /*
    |--------------------------------------------------------------------------
    | Compiling
    |--------------------------------------------------------------------------
    */

    /**
     * Validate names and compile the named scopes.
     *
     * @internal
     */
    public function seal(Model $prototype): self
    {
        $this->assertOpen();

        foreach ($this->states as $name => $state) {
            $this->claim($prototype, $name, "state [{$name}]", [$state, true]);

            foreach ($state->inverses() as $inverse) {
                $this->claim($prototype, $inverse, "the inverse of [{$name}]", [$state, false]);
            }

            foreach ($state->aliases() as $alias) {
                $this->claim($prototype, $alias, "an alias of [{$name}]", [$state, true]);
            }
        }

        // ->published(), ->published($moment), ->featured(false), ->featured(false, $moment)
        foreach (array_keys($this->lookup) as $method) {
            $this->methods[$method] = fn (Builder $query, mixed $activeOrAt = null, mixed $at = null) => is_bool($activeOrAt)
                ? $this->apply($query, $method, $activeOrAt, $at)
                : $this->apply($query, $method, true, $activeOrAt);
        }

        foreach ($this->filters as $name => $column) {
            $this->claimMethod($prototype, $name, "filter [{$name}]");
            $this->claimMethod($prototype, $inverse = 'not'.Str::studly($name), "filter [{$name}]");

            $this->methods[$name] = function (Builder $query, mixed ...$values) use ($column): Builder {
                $values = Values::list(count($values) === 1 && is_array($values[0]) ? $values[0] : $values);

                return $query->whereIn($query->qualifyColumn($column), $values);
            };

            $this->methods[$inverse] = function (Builder $query, mixed ...$values) use ($column): Builder {
                $values = Values::list(count($values) === 1 && is_array($values[0]) ? $values[0] : $values);
                $qualified = $query->qualifyColumn($column);

                return $query->where(fn (Builder $q) => $q->whereNotIn($qualified, $values)->orWhereNull($qualified));
            };
        }

        foreach ($this->sorters as $name => $columns) {
            $this->claimMethod($prototype, $name, "sort [{$name}]");

            $this->methods[$name] = function (Builder $query, ?string $direction = null) use ($columns): Builder {
                foreach ($columns as $index => [$column, $default]) {
                    $query->orderBy($query->qualifyColumn($column), $index === 0 && $direction !== null ? $direction : $default);
                }

                return $query;
            };
        }

        foreach (array_keys($this->dates) as $alias) {
            foreach (array_keys(self::DATE_SUFFIXES) as $suffix) {
                $this->assertFree($prototype, $alias.$suffix);
            }
        }

        // Longest alias first, so "updatedAt" never loses to "updated".
        uksort($this->dates, fn (string $a, string $b) => strlen($b) <=> strlen($a));

        $this->sealed = true;

        return $this;
    }

    private function resolveDateMethod(string $method): ?Closure
    {
        foreach ($this->dates as $alias => $column) {
            if (! str_starts_with($method, $alias)) {
                continue;
            }

            $factory = self::DATE_SUFFIXES[substr($method, strlen($alias))] ?? null;

            if ($factory !== null) {
                return fn (Builder $query, mixed ...$arguments) => Period::$factory(...$arguments)->apply($query, $column);
            }
        }

        return null;
    }

    /**
     * @param  array{0: State, 1: bool}  $target
     */
    private function claim(Model $prototype, string $name, string $owner, array $target): void
    {
        $this->claimMethod($prototype, $name, $owner);
        $this->lookup[$name] = $target;
    }

    private function claimMethod(Model $prototype, string $name, string $owner): void
    {
        if (isset($this->lookup[$name]) || isset($this->methods[$name])) {
            throw InvalidDefinition::duplicate($this->model, $name, $owner);
        }

        $this->assertFree($prototype, $name);

        // Reserve now, compiled later.
        $this->methods[$name] = static fn () => throw new LogicException('Not compiled.');
    }

    private function assertFree(Model $prototype, string $name): void
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw InvalidDefinition::column($this->model, $name, 'is not a valid method name.');
        }

        if (method_exists($prototype, 'scope'.ucfirst($name))) {
            throw InvalidDefinition::existingScope($this->model, $name);
        }

        foreach ([$prototype::class, Builder::class, QueryBuilder::class] as $owner) {
            if (method_exists($owner, $name)) {
                throw InvalidDefinition::reserved($this->model, $name, $owner);
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->sealed) {
            throw new LogicException("Scopes for {$this->model} are sealed; declare them in defineScopes().");
        }
    }
}
