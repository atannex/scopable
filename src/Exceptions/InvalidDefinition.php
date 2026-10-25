<?php

declare(strict_types=1);

namespace Atannex\Scopable\Exceptions;

final class InvalidDefinition extends ScopableException
{
    public static function duplicate(string $model, string $name, string $existing): self
    {
        return new self("{$model}: the scope name [{$name}] is already used by {$existing}.");
    }

    public static function reserved(string $model, string $name, string $owner): self
    {
        return new self("{$model}: [{$name}] cannot be a scope name, it is a method of {$owner}. Pick another name.");
    }

    public static function existingScope(string $model, string $name): self
    {
        return new self("{$model}: [{$name}] is already a scope method on the model. Remove the old scope or rename the state.");
    }

    public static function cycle(string $model, array $path): self
    {
        return new self("{$model}: states refer to each other in a loop (".implode(' → ', $path).').');
    }

    public static function column(string $model, string $name, string $reason): self
    {
        return new self("{$model}: state [{$name}] {$reason}");
    }
}
