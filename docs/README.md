# atannex/scopable

[![Tests](https://github.com/atannex/scopable/actions/workflows/tests.yml/badge.svg)](https://github.com/atannex/scopable/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012-FF2D20?logo=laravel&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-2ea44f.svg)](LICENSE)

Declarative state and date scopes for Laravel Eloquent. Declare a model's states once, then use them on queries and on loaded models.

```php
Post::published()->featured()->createdThisWeek()->ordered()->get();
$post->inState('scheduled');                    // no query
$post->enterState('breaking', until: now()->addHours(6));
```

Every state is a **rule** that does four things, so `Post::published()` and `$post->inState('published')` can never disagree:

1. **constrain a query** to rows in the state;
2. **constrain a query to the inverse**, NULLs included, so a state and its inverse always split the table in two;
3. **check a loaded model** in PHP, with no query;
4. **change the model** so it enters or leaves the state.

What you get:

- **States**: published / scheduled / draft, boolean flags (`is_active`, `is_featured`, `is_global`…), schedule windows (`starts_at` / `ends_at`), expiry, "highlights" (a flag that only counts inside its window, e.g. breaking news), enum and status values, counters under a cap, "within the last N", "older than N", relations, references to other states, and `all` / `any` / `not` composites.
- **Inverses and aliases**: every state gets `not{State}()`, plus any names you add (`unpublished`, `inactive`, `nonGlobal`).
- **Moments**: every state takes an optional moment, e.g. `Post::published($tomorrow)`, `$post->inState('published', '2026-12-25')`.
- **Period scopes** for every date column: `createdToday()`, `updatedWithin('7 days')`, `publishedLastMonth()`, `expiresInNext('3 days')`, `createdBetween('2026-01-01', '2026-03-31')`, `createdIn('last quarter')`… They compare the raw column against bounds (`col >= ? AND col < ?`), so indexes are used and no `DATE()` function runs per row. Calendar periods are cut in your newsroom's timezone.
- **Filters and sorts**: `->flagged('a', 'b')`, `->notFlagged('a')`, `->ordered()`, `->ordered('desc')`.
- **Default states**: hide unpublished rows from every query, like soft deletes, and lift that per query.
- **Events**: `StateEntered` / `StateLeft` when a save moves a tracked state, and when the clock does (`scopable:sweep`). They are dispatched after commit.
- **Real named scopes**: states work statically, on relations, inside `whereHas`, in `->scopes([...])`, and anywhere Eloquent checks `hasNamedScope()` (atannex/retrive's Retriever, for example).
- **Safe names**: a state that would shadow an Eloquent, query builder or model method (`count`, `latest`, `fresh`, `created`…), or an existing `scopeX` method, is refused when the model boots.
- **Tooling**: `scopable:show` (states, rules and SQL), `scopable:sweep`, and a Filament `StateFilter`.

## Install

```bash
composer require atannex/scopable
php artisan vendor:publish --tag=scopable-config   # optional
```

## Declare

```php
use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Scopes;

class Post extends Model
{
    use HasScopes;

    public static function defineScopes(Scopes $scopes): void
    {
        // published_at: published (set, passed) / scheduled (set, ahead) / draft (null)
        // / unpublished (scheduled or draft), plus publishedToday(), publishedWithin()…
        $scopes->published();

        // A boolean column: is_featured. ->featured(), ->notFeatured(), ->featured(false)
        $scopes->toggle('featured');

        // A flag that only counts inside its window.
        $scopes->highlight('breaking', 'is_breaking', 'breaking_at', 'breaking_expires')->tracked();

        // Anything, from the rules below, including other states.
        $scopes->state('spotlight', Rule::all(
            Rule::state('published'),
            Rule::any(Rule::state('featured'), Rule::state('breaking')),
        ));

        $scopes->filter('flagged', 'flag');            // ->flagged(Flag::PUBLISHED)
        $scopes->sortable('order', then: ['created_at' => 'desc']);
    }
}
```

### Shortcuts

| Declaration                                                                                                | Scopes it adds                                                                      |
| ---------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------- |
| `published($column = 'published_at')`                                                                      | `published`, `scheduled`, `draft`, `unpublished` (+ `notX`), date alias `published` |
| `timestamp($name, $column, pending:, missing:, inverse:, date:)`                                           | the same, with your names                                                           |
| `toggle($name, $column = "is_{$name}", inverse:)`                                                          | `$name`, `not{Name}`, `inverse`                                                     |
| `window($name, $from = 'starts_at', $until = 'ends_at', upcoming:, ended:, fromRequired:, untilRequired:)` | `$name` (now in [from, until)), optional `upcoming` and `ended`                     |
| `expires($column = 'expires_at', $name = 'expired', inverse: 'unexpired')`                                 | `expired`, `unexpired`, date alias `expires` (`expiresInNext('7 days')`)            |
| `highlight($name, $flag, $from, $until = null)`                                                            | flag true, start reached (required), end open or ahead                              |
| `equals($name, $column, $values, otherwise:)`                                                              | column in values; `otherwise` is written by `leaveState()`                          |
| `state($name, Rule $rule)`                                                                                 | anything                                                                            |
| `date($alias, $column)`                                                                                    | every period scope for that column                                                  |
| `filter($name, $column)`                                                                                   | `$name(...$values)`, `not{Name}(...$values)` (keeps NULLs)                          |
| `sortable($column, $name = 'ordered', $direction = 'asc', then: [])`                                       | `$name(?$direction)`                                                                |

`created`, `updated` and `deleted` date aliases are added automatically when the model has those columns (see `scopable.dates`).

### States from traits

A trait can bring its own states, like Laravel's `boot{Trait}`: a static `defineScopes{TraitName}(Scopes $scopes)` runs before the model's `defineScopes()`. That is how a package concern (matrix's `IsModerated`) gives every model using it the same states, while the model adds its own:

```php
trait IsModerated
{
    use HasScopes;

    public static function defineScopesIsModerated(Scopes $scopes): void
    {
        $scopes->equals('approved', 'status', Status::Approved, otherwise: Status::Pending);
        $scopes->equals('pending', 'status', Status::Pending);
    }
}
```

Every state can be chained with:

```php
->inverse('inactive')    // more names for the opposite
->alias('live')          // more names for the state
->label('Breaking news') // for the Filament filter and scopable:show
->byDefault()            // apply to every query
->tracked()              // fire StateEntered / StateLeft
```

### Rules

| Rule                                                                              | Passes when                                                               | Inverse               |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------- | --------------------- |
| `Rule::toggle('is_active', true)`                                                 | column is `true`                                                          | `false` or NULL       |
| `Rule::reached('published_at')`                                                   | set and `<= now`                                                          | NULL or `> now`       |
| `Rule::pending('published_at')`                                                   | set and `> now`                                                           | NULL or `<= now`      |
| `Rule::missing('col')` / `Rule::present('col')`                                   | NULL / NOT NULL                                                           | the other             |
| `Rule::window('starts_at', 'ends_at', fromRequired: false, untilRequired: false)` | now in `[from, until)`; NULL bounds are open unless required              | outside               |
| `Rule::upcoming('starts_at')` / `Rule::ended('ends_at')`                          | start ahead / end passed                                                  |                       |
| `Rule::equals('status', Status::Active)`, `Rule::in('flag', [...])`               | value matches (enum casts compare by value)                               | anything else or NULL |
| `Rule::compare('budget', '>=', 1000)`                                             | comparison holds; the value may be `fn ($now) => …`                       | the opposite or NULL  |
| `Rule::under('impressions', 'max_impressions')`                                   | cap is NULL (unlimited) or used < cap; a NULL counter counts as 0         | cap reached           |
| `Rule::within('created_at', '7 days')`                                            | in the last 7 days                                                        |                       |
| `Rule::olderThan('updated_at', '1 year')`                                         | earlier than a year ago                                                   |                       |
| `Rule::related('category', 'published')`                                          | a related row (in that state) exists                                      | none does             |
| `Rule::state('published')`                                                        | another state of the same model (loops are refused)                       |                       |
| `Rule::all(...)`, `Rule::any(...)`, `Rule::not(...)`                              | composites                                                                | De Morgan             |
| `Rule::callback($query, $passes, $inverse)`                                       | anything; without `$passes`, saved models are checked with a query by key |                       |

## Query

```php
Post::published()->get();
Post::published(now()->addDay());                 // as of a moment
Post::featured(false);                            // same as ->notFeatured()
Post::whereState(['published', 'featured']);
Post::whereNotState('breaking');
Post::whereAnyState(['breaking', 'featured']);
Post::published()->orWhereState('scheduled');
Comment::countStates(['pending', 'approved', 'spam']);   // ['pending' => 3, …] in ONE query
$category->posts()->published()->latest('published_at');
Category::whereHas('posts', fn ($q) => $q->breaking());
```

### Periods

For every date alias (`created`, `updated`, `deleted`, `published`, and any you register):

| Suffix                                       | Example                                                                             |
| -------------------------------------------- | ----------------------------------------------------------------------------------- |
| `Today` `Yesterday` `Tomorrow`               | `createdToday()`                                                                    |
| `ThisWeek` `LastWeek` `NextWeek`             | `publishedThisWeek()`                                                               |
| `ThisMonth` `LastMonth` `NextMonth`          | `updatedLastMonth()`                                                                |
| `ThisQuarter` `LastQuarter` `NextQuarter`    | `createdThisQuarter()`                                                              |
| `ThisYear` `LastYear` `NextYear`             | `createdLastYear()`                                                                 |
| `InPast` `InFuture`                          | `publishedInFuture()`                                                               |
| `Between($from, $to)`                        | `createdBetween('2026-01-01', '2026-03-31')`; a date-only end covers that whole day |
| `After` `Since` `Before` `Until`             | `After`/`Before` are strict; `Since`/`Until` include the moment                     |
| `On($date)`                                  | `createdOn('2026-05-03')`                                                           |
| `Within($duration)`                          | `updatedWithin('7 days')`                                                           |
| `OlderThan($duration)`                       | `updatedOlderThan('1 year')`                                                        |
| `InNext($duration)`                          | `expiresInNext('3 days')`                                                           |
| `InYear($y)` `InMonth($m)` `InMonth($y, $m)` | `createdInMonth(2026, 5)`                                                           |
| `In($text)`                                  | `createdIn('last 30 days')`, `createdIn('this week')`, `createdIn('2026-05')`       |

`lastDays(7)` / `In('last 7 calendar days')` is today plus the six days before, for day-based stats. A column cast to `date` (a rollup's `day`) is compared with dates, not datetimes. `Period::constrain($query, 'created_at', 'today')` works on any model, `HasScopes` or not, and `$period->applyOverlap($query, 'starts_at', 'ends_at')` keeps rows whose own window overlaps the period.

You can also use `->wherePeriod('created', 'last month')`, `->wherePeriod('starts_at', Period::nextWeek())` or `$post->inPeriod('published', 'today')`.

`Period` works on its own too: `Period::thisWeek()->apply($query, 'column')`, `Period::parse('older than 1 year')->contains($date)`.

## Models

```php
$post->inState('published');                 // from loaded attributes, no query
$post->inState('published', $tomorrow);
$post->stateMap();                           // ['published' => true, 'scheduled' => false, …]

$post->enterState('published');              // keeps an earlier publish date
$post->scheduleState('published', $friday);
$post->leaveState('published');              // clears published_at
$post->enterState('breaking', until: now()->addHours(6));
$post->leaveState('breaking');               // flag off, dates kept for the record
$post->setState('featured')->setState('published')->save();  // set without saving
```

How rules change a model:

- **toggle**: sets the flag on or off.
- **reached**: sets the date to now (or `$from`), but keeps an earlier date that has already passed. Leaving clears the date.
- **window**: starts now unless already started, sets `until`, and reopens an ended window. Leaving ends it now.
- **equals**: writes the first value. Leaving writes `otherwise`.
- **all**: enters every part. Leaving goes through the first part that can be left.
- **any**: enters the first part that can be entered. Leaving leaves every part.

Rules that cannot be set directly (`under`, `within`, `callback`…) throw `CannotChangeState` with the reason.

## Default states

```php
$scopes->published()->byDefault();

Note::all();                                      // published only
Note::withoutStateDefaults()->get();              // everything
Note::withoutStateDefaults('published')->draft(); // lift one default
```

## Events

`->tracked()` states fire `Atannex\Scopable\Events\StateEntered` / `StateLeft` with `$model`, `$state`, `$cause` (`saved` or `time`) and `$at`. They are dispatched after the transaction commits, so a rollback never announces anything. A row created already in a tracked state fires `StateEntered`.

The clock moves states without a save (a scheduled post goes live; a campaign ends). List the models in `scopable.models` and schedule the sweep:

```php
Schedule::command('scopable:sweep')->everyMinute()->withoutOverlapping()->onOneServer();
```

The sweep looks only at rows with a date that crossed "now" since the last run (an indexed range per boundary column), compares each tracked state then and now, and fires the difference. Use `--since=` to replay a window and `--model=` to limit the models.

Set `scopable.events` to `false` to turn off save events.

## Filament

Needs filament/filament.

```php
use Atannex\Scopable\Filament\{PeriodFilter, PeriodSelectFilter, PeriodTab, StateFilter, StateTab};

->filters([
    StateFilter::make('breaking'),                                      // yes / no / any
    PeriodFilter::make('published_at', __('Published')),                 // from / until, whole days
    PeriodFilter::window('starts_at', 'ends_at', __('Running')),         // windows overlapping the dates
    PeriodSelectFilter::make('created_at', ['today' => __('Today'), 'within 1 week' => __('This week')]),
])

public function getTabs(): array
{
    $counts = Comment::countStates(['pending', 'approved', 'spam', 'rejected']);

    return [
        'pending' => StateTab::make(Comment::class, 'pending', __('Pending'), count: $counts['pending'] ?: null),
        'rejected' => StateTab::make(Comment::class, ['spam', 'rejected'], __('Spam & rejected'), any: true),
        'today' => PeriodTab::make(Click::class, 'created', 'today', __('Today'), count: true),
        'week' => PeriodTab::make(EngagementDay::class, 'day', Period::lastDays(7)),
    ];
}
```

`count: true` runs one count per tab; pass numbers from `countStates()` to count every tab in one query.

## Tooling

```bash
php artisan scopable:show Post --sql    # states, inverses, rules, SQL, dates, filters, sorts
php artisan scopable:sweep
```

## Config

| Key                                              | Default                          |                                                                                                |
| ------------------------------------------------ | -------------------------------- | ---------------------------------------------------------------------------------------------- |
| `timezone`                                       | app timezone                     | the timezone calendar periods are cut in; bounds are converted to `app.timezone` for the query |
| `week_starts_on`                                 | `1` (Monday)                     |                                                                                                |
| `dates`                                          | created, updated, deleted        | automatic date aliases                                                                         |
| `models`                                         | `[]`                             | models for `scopable:sweep` / `scopable:show`                                                  |
| `events`                                         | `true`                           | save events                                                                                    |
| `sweep.store` / `sweep.lookback` / `sweep.chunk` | default store / 10 minutes / 500 |                                                                                                |

## Testing

```bash
composer test
```

The suite runs every state of the fixtures against a grid of rows (NULLs, past, now, future) at many moments, and asserts that the query matches the PHP check and that the state and its inverse partition the table.
