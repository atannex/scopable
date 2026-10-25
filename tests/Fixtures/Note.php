<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Readers only ever see published notes unless the default is lifted.
 */
class Note extends Model
{
    use HasScopes;

    protected $guarded = [];

    protected $casts = ['published_at' => 'datetime', 'is_pinned' => 'boolean'];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->published()->byDefault();
        $scopes->toggle('pinned');
    }
}
