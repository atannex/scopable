<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Feature;

use Atannex\Scopable\Exceptions\UnknownState;
use Atannex\Scopable\Period;
use Atannex\Scopable\Tests\Fixtures\Article;
use Atannex\Scopable\Tests\Fixtures\Campaign;
use Atannex\Scopable\Tests\Fixtures\Category;
use Atannex\Scopable\Tests\Fixtures\Status;
use Atannex\Scopable\Tests\Fixtures\Token;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class QueryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00')); // a Wednesday
    }

    public function test_published_scheduled_draft_and_unpublished(): void
    {
        $live = Article::create(['title' => 'live', 'published_at' => now()->subDay()]);
        $soon = Article::create(['title' => 'soon', 'published_at' => now()->addDay()]);
        $draft = Article::create(['title' => 'draft']);

        $this->assertSame([$live->id], Article::published()->pluck('id')->all());
        $this->assertSame([$soon->id], Article::scheduled()->pluck('id')->all());
        $this->assertSame([$draft->id], Article::draft()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$soon->id, $draft->id], Article::unpublished()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$soon->id, $draft->id], Article::notPublished()->pluck('id')->all());

        // At another moment: tomorrow, the scheduled one is live too.
        $this->assertEqualsCanonicalizing([$live->id, $soon->id], Article::published(now()->addDays(2))->pluck('id')->all());
    }

    public function test_states_work_on_builders_relations_and_the_scopes_method(): void
    {
        $category = Category::create(['name' => 'News', 'is_visible' => true]);
        $category->articles()->create(['title' => 'a', 'published_at' => now()->subHour(), 'is_featured' => true]);
        $category->articles()->create(['title' => 'b', 'published_at' => now()->subHour()]);

        $this->assertSame(1, $category->articles()->published()->featured()->count());
        $this->assertSame(1, Article::query()->scopes(['published', 'notFeatured'])->count());
        $this->assertSame(1, Category::whereHas('articles', fn ($q) => $q->featured())->count());
        $this->assertSame(1, Category::visible()->count());
        $this->assertSame(0, Category::hidden()->count());
        $this->assertTrue((new Article)->hasNamedScope('published'));
        $this->assertFalse((new Article)->hasNamedScope('nonsense'));
    }

    public function test_highlight_needs_the_flag_a_reached_start_and_an_open_end(): void
    {
        $on = Article::create(['title' => 'on', 'is_breaking' => true, 'breaking_at' => now()->subHour()]);
        Article::create(['title' => 'no start', 'is_breaking' => true]);
        Article::create(['title' => 'expired', 'is_breaking' => true, 'breaking_at' => now()->subDay(), 'breaking_expires' => now()->subMinute()]);
        Article::create(['title' => 'flag off', 'is_breaking' => false, 'breaking_at' => now()->subHour()]);
        $later = Article::create(['title' => 'until later', 'is_breaking' => true, 'breaking_at' => now()->subHour(), 'breaking_expires' => now()->addHour()]);

        $this->assertEqualsCanonicalizing([$on->id, $later->id], Article::breaking()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$on->id, $later->id], Article::live()->pluck('id')->all(), 'alias');
        $this->assertSame(3, Article::notBreaking()->count());
    }

    public function test_composite_and_referenced_states(): void
    {
        Article::create(['title' => 'featured live', 'published_at' => now()->subHour(), 'is_featured' => true]);
        Article::create(['title' => 'breaking live', 'published_at' => now()->subHour(), 'is_breaking' => true, 'breaking_at' => now()->subMinute()]);
        Article::create(['title' => 'featured draft', 'is_featured' => true]);
        Article::create(['title' => 'plain live', 'published_at' => now()->subHour()]);

        $this->assertEqualsCanonicalizing(['featured live', 'breaking live'], Article::spotlight()->pluck('title')->all());
        $this->assertEqualsCanonicalizing(['featured draft', 'plain live'], Article::notSpotlight()->pluck('title')->all());
    }

    public function test_or_groups_never_leak_into_the_outer_query(): void
    {
        Article::create(['title' => 'x', 'published_at' => now()->subHour(), 'is_featured' => true]);
        Article::create(['title' => 'y', 'is_featured' => true]);

        // spotlight contains ORs; the outer where must still apply.
        $this->assertSame(0, Article::where('title', 'y')->spotlight()->count());
        $this->assertSame(1, Article::spotlight()->where('title', 'x')->count());
    }

    public function test_related_states(): void
    {
        $shown = Category::create(['name' => 'shown', 'is_visible' => true]);
        $hidden = Category::create(['name' => 'hidden', 'is_visible' => false]);
        $a = $shown->articles()->create(['title' => 'a']);
        $hidden->articles()->create(['title' => 'b']);
        Article::create(['title' => 'orphan']);

        $this->assertSame([$a->id], Article::listed()->pluck('id')->all());
        $this->assertSame(2, Article::notListed()->count());
        $this->assertTrue($a->fresh()->inState('listed'));
    }

    public function test_enum_values_windows_limits_and_comparisons(): void
    {
        $running = Campaign::create(['status' => Status::Active, 'starts_at' => now()->subDay(), 'impressions' => 5, 'max_impressions' => 10, 'budget' => 2000]);
        $capped = Campaign::create(['status' => Status::Active, 'impressions' => 10, 'max_impressions' => 10]);
        $upcoming = Campaign::create(['status' => Status::Active, 'starts_at' => now()->addDay()]);
        $ended = Campaign::create(['status' => Status::Active, 'ends_at' => now()->subSecond()]);
        $paused = Campaign::create(['status' => Status::Paused]);

        $this->assertSame([$running->id], Campaign::running()->pluck('id')->all());
        $this->assertSame([$upcoming->id], Campaign::upcoming()->pluck('id')->all());
        $this->assertSame([$ended->id], Campaign::ended()->pluck('id')->all());
        $this->assertSame([$capped->id], Campaign::notWithinLimits()->pluck('id')->all());
        $this->assertSame([$paused->id], Campaign::notActive()->pluck('id')->all());
        $this->assertSame([$running->id], Campaign::big()->pluck('id')->all());
        $this->assertTrue($running->inState('running'));
        $this->assertFalse($capped->inState('running'));
    }

    public function test_generic_state_scopes(): void
    {
        Article::create(['title' => 'p', 'published_at' => now()->subDay()]);
        Article::create(['title' => 'f', 'is_featured' => true]);
        Article::create(['title' => 'n']);

        $this->assertSame(['p'], Article::whereState('published')->pluck('title')->all());
        $this->assertSame(['f', 'n'], Article::whereState('published', false)->pluck('title')->all());
        $this->assertSame(['n'], Article::whereNotState(['published', 'featured'])->pluck('title')->all());
        $this->assertSame(['p', 'f'], Article::whereAnyState(['published', 'featured'])->pluck('title')->all());
        $this->assertSame(['p', 'f'], Article::published()->orWhereState('featured')->pluck('title')->all());
    }

    public function test_unknown_states_explain_themselves(): void
    {
        $this->expectException(UnknownState::class);
        $this->expectExceptionMessage('Known states: published');

        Article::whereState('archived')->get();
    }

    public function test_value_filters_accept_enums_lists_and_keep_nulls_on_the_inverse(): void
    {
        Article::create(['title' => 'p', 'flag' => 'published']);
        Article::create(['title' => 'd', 'flag' => 'draft']);
        Article::create(['title' => 'n']);

        $this->assertSame(['p'], Article::flagged('published')->pluck('title')->all());
        $this->assertSame(['p', 'd'], Article::flagged('published', 'draft')->pluck('title')->all());
        $this->assertSame(['p', 'd'], Article::flagged(['published', 'draft'])->pluck('title')->all());
        $this->assertSame(['d', 'n'], Article::notFlagged('published')->pluck('title')->all());
    }

    public function test_sort_orders_with_tie_breakers(): void
    {
        Article::create(['title' => 'b', 'order' => 2]);
        Article::create(['title' => 'a1', 'order' => 1]);
        Article::create(['title' => 'a2', 'order' => 1]);

        $this->assertSame(['a2', 'a1', 'b'], Article::ordered()->pluck('title')->all());
        $this->assertSame(['b', 'a2', 'a1'], Article::ordered('desc')->pluck('title')->all());
    }

    public function test_date_scopes_for_every_alias(): void
    {
        $make = fn (string $title, string $created, ?string $published = null) => tap(
            Article::create(['title' => $title, 'published_at' => $published]),
            fn (Article $a) => $a->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly(),
        );

        $make('today', '2026-06-17 08:00:00', '2026-06-17 09:00:00');
        $make('yesterday', '2026-06-16 23:59:59', '2026-06-10 00:00:00');
        $make('monday', '2026-06-15 00:00:00');
        $make('last week', '2026-06-14 23:00:00');
        $make('last month', '2026-05-20 10:00:00', '2026-05-31 23:59:59');
        $make('last year', '2025-12-31 23:59:59');

        $titles = fn ($q) => $q->orderBy('id')->pluck('title')->all();

        $this->assertSame(['today'], $titles(Article::createdToday()));
        $this->assertSame(['yesterday'], $titles(Article::createdYesterday()));
        $this->assertSame(['today', 'yesterday', 'monday'], $titles(Article::createdThisWeek()));
        $this->assertSame(['last week'], $titles(Article::createdLastWeek()));
        $this->assertSame(['last month'], $titles(Article::createdLastMonth()));
        $this->assertSame(['last year'], $titles(Article::createdLastYear()));
        $this->assertSame(['today', 'yesterday', 'monday', 'last week', 'last month'], $titles(Article::createdThisYear()));
        $this->assertSame(['today', 'yesterday', 'monday', 'last week', 'last month'], $titles(Article::createdThisQuarter()));
        $this->assertSame(['today', 'yesterday'], $titles(Article::createdWithin('1 day')));
        $this->assertSame(['last month', 'last year'], $titles(Article::createdOlderThan('1 week')));
        $this->assertSame(['monday'], $titles(Article::createdOn('2026-06-15')));
        $this->assertSame(['yesterday', 'monday', 'last week'], $titles(Article::createdBetween('2026-06-14', '2026-06-16')));
        $this->assertSame(['today', 'yesterday', 'monday'], $titles(Article::createdSince('2026-06-15')));
        $this->assertSame(['last week', 'last month', 'last year'], $titles(Article::createdBefore('2026-06-15')));
        $this->assertSame(['last year'], $titles(Article::createdInYear(2025)));
        $this->assertSame(['last month'], $titles(Article::createdInMonth(2026, 5)));
        $this->assertSame(['last month'], $titles(Article::createdInMonth(5)));
        $this->assertSame(['last month'], $titles(Article::createdIn('2026-05')));
        $this->assertSame(['today', 'yesterday'], $titles(Article::createdIn('last 24 hours')));
        $this->assertSame(['today', 'yesterday'], $titles(Article::updatedWithin('1 day')));
        $this->assertSame(['today'], $titles(Article::publishedToday()));
        $this->assertSame(['yesterday', 'last month'], $titles(Article::publishedBefore('2026-06-17')));
        $this->assertSame(['last month'], $titles(Article::publishedLastMonth()));
        $this->assertSame(['today', 'yesterday', 'last month'], $titles(Article::wherePeriod('published', 'this year')->where('published_at', '>', '2026-05-01')));
        $this->assertSame(['monday'], $titles(Article::wherePeriod('created_at', Period::on('2026-06-15'))));

        $this->assertTrue(Article::where('title', 'today')->first()->inPeriod('created', 'today'));
        $this->assertFalse(Article::where('title', 'monday')->first()->inPeriod('created', 'today'));
    }

    public function test_deleted_dates_with_soft_deletes(): void
    {
        $article = Article::create(['title' => 'gone']);
        $article->delete();

        $this->assertSame(1, Article::withTrashed()->deletedToday()->count());
        $this->assertSame(0, Article::withTrashed()->deletedLastWeek()->count());
    }

    public function test_period_bounds_compare_raw_columns_so_indexes_are_used(): void
    {
        $sql = Article::createdToday()->toSql();

        $this->assertStringContainsString('"articles"."created_at" >= ?', $sql);
        $this->assertStringContainsString('"articles"."created_at" < ?', $sql);
        $this->assertStringNotContainsStringIgnoringCase('date(', $sql);
    }

    public function test_columns_are_qualified_so_joins_stay_unambiguous(): void
    {
        $category = Category::create(['name' => 'News', 'is_visible' => true]);
        $category->articles()->create(['title' => 'a', 'published_at' => now()->subDay()]);

        $count = Article::query()
            ->join('categories', 'categories.id', '=', 'articles.category_id')
            ->published()
            ->createdToday()
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_calendar_periods_follow_the_calendar_timezone(): void
    {
        config(['scopable.timezone' => 'Africa/Douala']); // UTC+1

        // 23:30 UTC on the 16th is 00:30 on the 17th in Douala.
        $article = Article::create(['title' => 'late']);
        $article->forceFill(['created_at' => '2026-06-16 23:30:00'])->saveQuietly();

        $this->assertSame(1, Article::createdToday()->count());
        $this->assertSame(0, Article::createdYesterday()->count());

        config(['scopable.timezone' => null]);

        $this->assertSame(0, Article::createdToday()->count());
        $this->assertSame(1, Article::createdYesterday()->count());
    }

    public function test_expiry(): void
    {
        $valid = Token::create(['expires_at' => now()->addDays(30)]);
        $soon = Token::create(['expires_at' => now()->addDays(2)]);
        $forever = Token::create([]);
        $expired = Token::create(['expires_at' => now()->subMinute()]);

        $this->assertSame([$expired->id], Token::expired()->pluck('id')->all());
        $this->assertSame([$valid->id, $soon->id, $forever->id], Token::unexpired()->pluck('id')->all());
        $this->assertSame([$soon->id], Token::expiresInNext('7 days')->pluck('id')->all());
    }

    public function test_one_query_per_call(): void
    {
        Article::create(['title' => 'a', 'published_at' => now()->subDay()]);

        DB::enableQueryLog();
        Article::published()->featured(false)->notBreaking()->createdThisWeek()->ordered()->get();

        $this->assertCount(1, DB::getQueryLog());
    }
}
