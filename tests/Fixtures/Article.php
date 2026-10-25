<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Article extends Model
{
    use HasScopes;
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'published_at' => 'datetime',
        'breaking_at' => 'datetime',
        'breaking_expires' => 'datetime',
        'is_featured' => 'boolean',
        'is_breaking' => 'boolean',
    ];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->published();
        $scopes->toggle('featured');
        $scopes->highlight('breaking', 'is_breaking', 'breaking_at', 'breaking_expires')->alias('live')->tracked();
        $scopes->state('spotlight', Rule::all(Rule::state('published'), Rule::any(Rule::state('featured'), Rule::state('breaking'))));
        $scopes->state('listed', Rule::related('category', 'visible'));
        $scopes->state('recent', Rule::within('published_at', '2 days'));
        $scopes->state('stale', Rule::olderThan('updated_at', '1 year'));
        $scopes->filter('flagged', 'flag');
        $scopes->sortable('order', then: ['id' => 'desc']);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
