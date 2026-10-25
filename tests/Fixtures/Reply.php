<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Uses HasScopes directly and through Moderated, and adds its own state.
 */
class Reply extends Model
{
    use HasScopes;
    use Moderated;

    protected $table = 'campaigns';

    protected $guarded = [];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->equals('paused', 'status', 'paused');
    }
}
