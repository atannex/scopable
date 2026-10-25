<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * A state applied to every query on the model (State::byDefault()).
 * Each has its own identifier, so one can be lifted on its own.
 */
final class DefaultState implements Scope
{
    public function __construct(public readonly string $state) {}

    public static function identifier(string $state): string
    {
        return 'scopable:'.$state;
    }

    public function apply(Builder $builder, Model $model): void
    {
        Registry::for($model::class)->apply($builder, $this->state);
    }
}
