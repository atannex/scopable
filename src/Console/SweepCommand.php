<?php

declare(strict_types=1);

namespace Atannex\Scopable\Console;

use Atannex\Scopable\Support\Clock;
use Atannex\Scopable\Support\Tracker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as Cache;

/**
 * Fire StateEntered / StateLeft for tracked states the clock has moved
 * (a scheduled post going live, an offer expiring). Schedule it:
 *
 *     Schedule::command('scopable:sweep')->everyMinute()->withoutOverlapping()->onOneServer();
 */
final class SweepCommand extends Command
{
    use ResolvesModels;

    protected $signature = 'scopable:sweep
        {--model=* : Only these models (default: scopable.models)}
        {--since= : Look back to this moment instead of the last sweep}';

    protected $description = 'Fire state events for tracked states that changed with time';

    public function handle(Tracker $tracker, Cache $cache): int
    {
        $store = $cache->store(config('scopable.sweep.store'));
        $names = $this->option('model') ?: (array) config('scopable.models', []);
        $to = Clock::now();
        $total = 0;

        foreach ($names as $name) {
            $class = $this->resolveModel($name);

            if ($class === null) {
                $this->components->error("[{$name}] is not a model using HasScopes.");

                continue;
            }

            $key = 'scopable:sweep:'.$class;
            $since = $this->option('since');
            $last = $since !== null ? Clock::at($since) : $store->get($key);

            $from = $last !== null
                ? Clock::at($last)
                : $to->sub(Clock::interval(config('scopable.sweep.lookback', '10 minutes')));

            $fired = $tracker->sweep($class, $from, $to, (int) config('scopable.sweep.chunk', 500));
            $store->forever($key, $to->toIso8601String());
            $total += $fired;

            $this->components->twoColumnDetail($class, $fired.' '.($fired === 1 ? 'change' : 'changes'));
        }

        $this->components->info("{$total} state ".($total === 1 ? 'change' : 'changes').' since the last sweep.');

        return self::SUCCESS;
    }
}
