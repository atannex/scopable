<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonInterval;

/**
 * A moment at which a rule can flip on its own: the value of $column plus
 * $offset (published_at; created_at + 7 days for "created within 7 days").
 */
final readonly class Boundary
{
    public function __construct(
        public string $column,
        public ?CarbonInterval $offset = null,
    ) {}
}
