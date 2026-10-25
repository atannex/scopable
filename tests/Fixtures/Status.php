<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

enum Status: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
}
