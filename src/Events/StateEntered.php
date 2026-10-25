<?php

declare(strict_types=1);

namespace Atannex\Scopable\Events;

/**
 * A model entered a tracked state (a scheduled post went live, a campaign started).
 */
final class StateEntered extends StateChanged
{
    public function entered(): bool
    {
        return true;
    }
}
