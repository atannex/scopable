<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Compiled definitions, once per model class per process.
 */
final class Registry
{
    /** @var array<class-string<Model>, Scopes> */
    private static array $scopes = [];

    /**
     * @param  class-string<Model>  $class
     */
    public static function for(string $class): Scopes
    {
        return self::$scopes[$class] ??= self::build($class);
    }

    /**
     * Forget compiled definitions (after changing config in tests).
     */
    public static function flush(?string $class = null): void
    {
        if ($class === null) {
            self::$scopes = [];

            return;
        }

        unset(self::$scopes[$class]);
    }

    /**
     * @param  class-string<Model>  $class
     */
    private static function build(string $class): Scopes
    {
        // A bare instance: no constructor, so no boot (which itself reads the registry).
        /** @var Model $prototype */
        $prototype = (new ReflectionClass($class))->newInstanceWithoutConstructor();

        $scopes = new Scopes($class);
        $scopes->registerDefaultDates($prototype);

        // Traits contribute first, like boot{Trait}: defineScopes{Trait}(Scopes $scopes).
        foreach (class_uses_recursive($class) as $trait) {
            $method = 'defineScopes'.class_basename($trait);

            if (method_exists($class, $method)) {
                $class::$method($scopes);
            }
        }

        if (method_exists($class, 'defineScopes')) {
            $class::defineScopes($scopes);
        }

        return $scopes->seal($prototype);
    }
}
