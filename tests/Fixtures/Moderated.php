<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;

/**
 * A trait that brings its own states, as a package model concern would.
 */
trait Moderated
{
    use HasScopes;

    public static function defineScopesModerated(Scopes $scopes): void
    {
        $scopes->equals('approved', 'status', 'approved');
        $scopes->equals('pending', 'status', 'pending');
    }
}
