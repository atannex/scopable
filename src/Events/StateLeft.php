<?php

declare(strict_types=1);

namespace Atannex\Scopable\Events;

/**
 * A model left a tracked state (breaking news expired, a token ran out).
 */
final class StateLeft extends StateChanged
{
    public function entered(): bool
    {
        return false;
    }
}
