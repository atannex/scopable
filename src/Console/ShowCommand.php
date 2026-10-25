<?php

declare(strict_types=1);

namespace Atannex\Scopable\Console;

use Atannex\Scopable\Registry;
use Atannex\Scopable\Scopes;
use Illuminate\Console\Command;

/**
 * php artisan scopable:show Post [--sql]
 */
final class ShowCommand extends Command
{
    use ResolvesModels;

    protected $signature = 'scopable:show
        {model? : Model class or short name (all of scopable.models when omitted)}
        {--sql : Print the SQL of each state}';

    protected $description = 'Show the states, dates, filters and sorts a model declares';

    public function handle(): int
    {
        $names = $this->argument('model') !== null ? [$this->argument('model')] : (array) config('scopable.models', []);

        if ($names === []) {
            $this->components->warn('Name a model, or list models in config/scopable.php.');

            return self::INVALID;
        }

        foreach ($names as $name) {
            $class = $this->resolveModel($name);

            if ($class === null) {
                $this->components->error("[{$name}] is not a model using HasScopes.");

                return self::FAILURE;
            }

            $this->show($class, Registry::for($class));
        }

        return self::SUCCESS;
    }

    private function show(string $class, Scopes $scopes): void
    {
        $this->components->twoColumnDetail("<fg=green;options=bold>{$class}</>");

        $rows = [];

        foreach ($scopes->states() as $state) {
            $rows[] = [
                $state->name,
                implode(', ', [...$state->inverses()]),
                implode(', ', $state->aliases()),
                $state->describe(),
                trim(($state->isDefault() ? 'default ' : '').($state->isTracked() ? 'tracked' : '')),
            ];

            if ($this->option('sql')) {
                $rows[] = ['', '', '', '<fg=gray>'.$class::query()->withoutStateDefaults()->whereState($state->name)->toRawSql().'</>', ''];
            }
        }

        $this->table(['State', 'Inverse', 'Aliases', 'Rule', 'Flags'], $rows);

        if ($scopes->dates() !== []) {
            $this->table(['Date', 'Column', 'Examples'], array_map(
                fn (string $alias, string $column) => [$alias, $column, "{$alias}Today(), {$alias}Within('7 days'), {$alias}In('last month')"],
                array_keys($scopes->dates()),
                $scopes->dates(),
            ));
        }

        foreach ($scopes->filters() as $name => $column) {
            $this->components->twoColumnDetail("filter <fg=yellow>{$name}</>(…values)", $column);
        }

        foreach ($scopes->sorters() as $name => $columns) {
            $this->components->twoColumnDetail("sort <fg=yellow>{$name}</>(?direction)", implode(', ', array_map(fn ($c) => "{$c[0]} {$c[1]}", $columns)));
        }

        $this->newLine();
    }
}
