<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Feature;

use Atannex\Scopable\Filament\PeriodFilter;
use Atannex\Scopable\Period;
use Atannex\Scopable\Tests\Fixtures\Article;
use Atannex\Scopable\Tests\Fixtures\Reply;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class ExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00'));
    }

    public function test_traits_contribute_states_alongside_the_model(): void
    {
        Reply::create(['status' => 'approved']);
        Reply::create(['status' => 'pending']);
        Reply::create(['status' => 'paused']);

        $this->assertSame(['approved', 'pending', 'paused'], array_keys(Reply::scopeDefinitions()->states()));
        $this->assertSame(1, Reply::approved()->count());
        $this->assertSame(1, Reply::pending()->count());
        $this->assertSame(1, Reply::paused()->count());
        $this->assertSame(2, Reply::notApproved()->count());
    }

    public function test_period_constrain_works_on_aliases_and_plain_models(): void
    {
        $article = Article::create(['title' => 'a']);
        $article->forceFill(['created_at' => '2026-06-17 08:00:00'])->saveQuietly();

        $this->assertSame(1, Period::constrain(Article::query(), 'created', 'today')->count());

        $plain = new class extends Model
        {
            protected $table = 'articles';
        };

        $this->assertSame(1, Period::constrain($plain->newQuery(), 'created_at', 'today')->count());
        $this->assertSame(0, Period::constrain($plain->newQuery(), 'articles.created_at', 'yesterday')->count());
    }

    public function test_the_period_filter_covers_whole_days(): void
    {
        foreach (['2026-06-10 00:00:00', '2026-06-12 23:59:59', '2026-06-13 00:00:00'] as $i => $at) {
            Article::create(['title' => "a{$i}"])->forceFill(['created_at' => $at])->saveQuietly();
        }

        $titles = fn ($from, $until) => PeriodFilter::apply(Article::query(), 'created', $from, $until)->pluck('title')->all();

        $this->assertSame(['a0', 'a1'], $titles('2026-06-10', '2026-06-12'));
        $this->assertSame(['a1', 'a2'], $titles('2026-06-11', null));
        $this->assertSame(['a0', 'a1'], $titles(null, '2026-06-12'));
        $this->assertSame(['a0', 'a1', 'a2'], $titles(null, null));
        $this->assertSame(['a0'], $titles('2026-06-10T00:00:00', '2026-06-10T00:00:00'), 'picker values with a time');
    }

    public function test_state_counts_in_one_query(): void
    {
        Article::create(['title' => 'live', 'published_at' => now()->subDay(), 'is_featured' => true]);
        Article::create(['title' => 'soon', 'published_at' => now()->addDay()]);
        Article::create(['title' => 'draft']);
        Article::create(['title' => 'gone', 'published_at' => now()->subDay()])->delete();

        DB::enableQueryLog();
        $counts = Article::countStates(['published', 'scheduled', 'draft', 'featured', 'spotlight', 'notFeatured']);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertSame(['published' => 1, 'scheduled' => 1, 'draft' => 1, 'featured' => 1, 'spotlight' => 1, 'notFeatured' => 2], $counts);
        $this->assertSame(['draft' => 1], Article::countStates(['draft'], Article::where('title', 'draft')));
        $this->assertSame(['published' => 2], Article::countStates(['published'], Article::withTrashed()));
    }

    public function test_date_cast_columns_compare_by_date(): void
    {
        $model = new class extends Model
        {
            protected $table = 'articles';

            protected $casts = ['published_at' => 'date'];
        };

        // Stored as plain dates, as a stats table's "day" column would be.
        foreach (['2026-06-11', '2026-06-10', '2026-06-17'] as $i => $day) {
            DB::table('articles')->insert(['title' => "d{$i}", 'published_at' => $day]);
        }

        $titles = Period::lastDays(7)->apply($model->newQuery(), 'published_at')->orderBy('id')->pluck('title')->all();

        $this->assertSame(['d0', 'd2'], $titles);
    }

    public function test_windows_overlapping_a_period(): void
    {
        $make = fn (string $name, ?string $start, ?string $end) => Reply::create(['status' => $name, 'starts_at' => $start, 'ends_at' => $end]);

        $make('before', '2026-06-01 00:00:00', '2026-06-05 00:00:00');
        $make('across', '2026-06-08 00:00:00', '2026-06-12 00:00:00');
        $make('open', null, null);
        $make('after', '2026-06-20 00:00:00', null);
        $make('endsOnStart', '2026-06-01 00:00:00', '2026-06-10 00:00:00');

        $names = fn (?string $from, ?string $until) => PeriodFilter::period($from, $until)
            ->applyOverlap(Reply::query(), 'starts_at', 'ends_at')
            ->orderBy('id')->get()->pluck('status')->all();

        $this->assertSame(['across', 'open'], $names('2026-06-10', '2026-06-15'));
        $this->assertSame(['across', 'open', 'after'], $names('2026-06-10', null));
        $this->assertSame(['before', 'across', 'open', 'endsOnStart'], $names(null, '2026-06-09'));
    }
}
