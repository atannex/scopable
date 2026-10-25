<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasScopes;

    protected $guarded = [];

    protected $casts = ['is_visible' => 'boolean'];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->toggle('visible', inverse: 'hidden');
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}
