<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Fixtures;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Scopes;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    use HasScopes;

    protected $guarded = [];

    protected $casts = [
        'status' => Status::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public static function defineScopes(Scopes $scopes): void
    {
        $scopes->equals('active', 'status', Status::Active, otherwise: Status::Paused);
        $scopes->window('scheduled', upcoming: 'upcoming', ended: 'ended')->tracked();
        $scopes->state('withinLimits', Rule::all(Rule::under('impressions', 'max_impressions'), Rule::under('spend', 'budget')));
        $scopes->state('running', Rule::all(Rule::state('active'), Rule::state('scheduled'), Rule::state('withinLimits')))->tracked();
        $scopes->state('big', Rule::compare('budget', '>=', 1000));
        $scopes->date('starts', 'starts_at');
    }
}
