<?php

declare(strict_types=1);

namespace Atannex\Scopable\Exceptions;

final class UnknownState extends ScopableException
{
    /**
     * @param  list<string>  $known
     */
    public static function named(string $model, string $name, array $known): self
    {
        $hint = $known === [] ? 'It declares no states.' : 'Known states: '.implode(', ', $known).'.';

        return new self("{$model} has no state [{$name}]. {$hint}");
    }

    public static function date(string $model, string $alias): self
    {
        return new self("{$model} has no date [{$alias}]. Register it with \$scopes->date('{$alias}', 'column').");
    }
}
