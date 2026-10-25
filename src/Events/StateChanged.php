<?php

declare(strict_types=1);

namespace Atannex\Scopable\Events;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A tracked state changed. $cause is "saved" (an edit moved it) or "time"
 * (the clock moved it; found by scopable:sweep). Dispatched after commit.
 */
abstract class StateChanged implements ShouldDispatchAfterCommit
{
    public const SAVED = 'saved';

    public const TIME = 'time';

    public function __construct(
        public readonly Model $model,
        public readonly string $state,
        public readonly string $cause,
        public readonly CarbonImmutable $at,
    ) {}

    abstract public function entered(): bool;
}
