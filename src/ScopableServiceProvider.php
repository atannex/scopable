<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Atannex\Scopable\Console\ShowCommand;
use Atannex\Scopable\Console\SweepCommand;
use Atannex\Scopable\Support\Tracker;
use Illuminate\Support\ServiceProvider;

class ScopableServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scopable.php', 'scopable');

        $this->app->singleton(Tracker::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/scopable.php' => $this->app->configPath('scopable.php'),
            ], 'scopable-config');

            $this->commands([ShowCommand::class, SweepCommand::class]);
        }
    }
}
