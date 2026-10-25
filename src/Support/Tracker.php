<?php

declare(strict_types=1);

namespace Atannex\Scopable\Support;

use Atannex\Scopable\Events\StateChanged;
use Atannex\Scopable\Events\StateEntered;
use Atannex\Scopable\Events\StateLeft;
use Atannex\Scopable\Registry;
use Atannex\Scopable\State;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds tracked states that changed, either because a save changed the
 * attributes or because the clock moved past one of their dates.
 */
final class Tracker
{
    /**
     * Compare the attributes before and after a save. A new row starts
     * outside every state.
     */
    public function saved(Model $model): void
    {
        if (! config('scopable.events', true)) {
            return;
        }

        $now = Clock::now();
        $before = $model->wasRecentlyCreated && $model->getRawOriginal() === [] ? null : $this->original($model);

        foreach (Registry::for($model::class)->tracked() as $state) {
            $was = $before !== null && $state->rule->passes($before, $now);
            $is = $state->rule->passes($model, $now);

            if ($was !== $is) {
                $this->dispatch($model, $state, $is, StateChanged::SAVED, $now);
            }
        }
    }

    /**
     * Fire events for rows whose tracked states flipped between $from and $to
     * without a save. Returns the number of events.
     *
     * @param  class-string<Model>  $class
     */
    public function sweep(string $class, CarbonImmutable $from, CarbonImmutable $to, int $chunk = 500): int
    {
        $states = array_filter(Registry::for($class)->tracked(), fn (State $state) => $state->rule->boundaries($class) !== []);

        if ($states === [] || $to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $boundaries = array_merge([], ...array_map(fn (State $state) => $state->rule->boundaries($class), $states));

        /** @var Builder $query */
        $query = $class::query()->withoutStateDefaults();

        // Only rows with a date that crossed "now" in (from, to].
        $query->where(function (Builder $q) use ($boundaries, $from, $to): void {
            foreach ($boundaries as $boundary) {
                $column = $q->qualifyColumn($boundary->column);
                $start = $boundary->offset ? $from->sub($boundary->offset) : $from;
                $end = $boundary->offset ? $to->sub($boundary->offset) : $to;

                $q->orWhere(fn (Builder $q) => $q->where($column, '>', Clock::bind($start))->where($column, '<=', Clock::bind($end)));
            }
        });

        $fired = 0;

        $query->chunkById($chunk, function ($models) use ($states, $from, $to, &$fired): void {
            foreach ($models as $model) {
                foreach ($states as $state) {
                    $was = $state->rule->passes($model, $from);
                    $is = $state->rule->passes($model, $to);

                    if ($was !== $is) {
                        $this->dispatch($model, $state, $is, StateChanged::TIME, $to);
                        $fired++;
                    }
                }
            }
        });

        return $fired;
    }

    private function dispatch(Model $model, State $state, bool $entered, string $cause, CarbonImmutable $at): void
    {
        // Resolved per dispatch, so a swapped dispatcher (Event::fake) is honoured.
        app(Dispatcher::class)->dispatch($entered
            ? new StateEntered($model, $state->name, $cause, $at)
            : new StateLeft($model, $state->name, $cause, $at));
    }

    /**
     * The model as it was before the save.
     */
    private function original(Model $model): Model
    {
        $original = $model->newInstance([], true);
        $original->setRawAttributes($model->getRawOriginal(), true);
        $original->setRelations($model->getRelations());

        return $original;
    }
}
