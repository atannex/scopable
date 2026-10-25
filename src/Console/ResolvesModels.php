<?php

declare(strict_types=1);

namespace Atannex\Scopable\Console;

use Atannex\Scopable\Concerns\HasScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * "App\Models\Posts\Post", "Posts\Post" or "Post" (matched against scopable.models).
 */
trait ResolvesModels
{
    /**
     * @return class-string<Model>|null
     */
    protected function resolveModel(string $name): ?string
    {
        $name = ltrim(str_replace('/', '\\', $name), '\\');

        $candidates = [$name, 'App\\Models\\'.$name];

        foreach ((array) config('scopable.models', []) as $class) {
            if (strcasecmp(class_basename($class), $name) === 0 || str_ends_with(strtolower($class), '\\'.strtolower($name))) {
                $candidates[] = $class;
            }
        }

        foreach ($candidates as $class) {
            if (class_exists($class) && is_subclass_of($class, Model::class) && in_array(HasScopes::class, class_uses_recursive($class), true)) {
                return $class;
            }
        }

        return null;
    }
}
