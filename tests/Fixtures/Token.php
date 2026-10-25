<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;

class Token extends Model
{
    use HasScopes;

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'last_used_at' => 'datetime'];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->expires();
        $scopes->state('neverUsed', Rule::missing('last_used_at'));
        $scopes->state('used', Rule::present('last_used_at'));
    }
}
