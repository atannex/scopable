<?php

declare(strict_types=1);

namespace Atannex\Scopable\Exceptions;

final class CannotChangeState extends ScopableException
{
    public static function because(string $rule, string $reason): self
    {
        return new self("Cannot change [{$rule}]: {$reason}");
    }
}
