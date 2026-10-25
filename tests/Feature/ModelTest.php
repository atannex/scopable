<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Feature;

use Atannex\Scopable\Exceptions\CannotChangeState;
use Atannex\Scopable\Tests\Fixtures\Article;
use Atannex\Scopable\Tests\Fixtures\Campaign;
use Atannex\Scopable\Tests\Fixtures\Status;
use Atannex\Scopable\Tests\Fixtures\Token;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;

final class ModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00'));
    }

    public function test_checks_need_no_query_and_accept_a_moment(): void
    {
        $article = new Article(['published_at' => now()->addHour()]);

        $this->assertFalse($article->inState('published'));
        $this->assertTrue($article->inState('scheduled'));
        $this->assertTrue($article->inState('unpublished'));
        $this->assertTrue($article->inState('published', now()->addHours(2)));
        $this->assertTrue($article->inState('published', '2026-06-17 13:00:00'));

        $this->assertSame(
            ['published' => false, 'scheduled' => true, 'draft' => false, 'featured' => false, 'breaking' => false],
            array_intersect_key($article->stateMap(), array_flip(['published', 'scheduled', 'draft', 'featured', 'breaking'])),
        );
    }

    public function test_publish_keeps_an_earlier_date_and_unpublish_clears_it(): void
    {
        $article = Article::create(['title' => 'a']);

        $this->assertTrue($article->enterState('published'));
        $this->assertTrue($article->published_at->equalTo(now()));

        $this->travel(1)->hours();
        $article->enterState('published');
        $this->assertTrue($article->published_at->equalTo(now()->subHour()), 'republishing keeps the first date');

        $article->leaveState('published');
        $this->assertNull($article->fresh()->published_at);
        $this->assertTrue($article->fresh()->inState('draft'));
    }

    public function test_scheduling(): void
    {
        $article = Article::create(['title' => 'a']);

        $article->scheduleState('published', now()->addDay());
        $this->assertTrue($article->fresh()->inState('scheduled'));

        $this->travel(2)->days();
        $this->assertTrue($article->fresh()->inState('published'));
    }

    public function test_entering_a_pending_state_needs_a_future_moment(): void
    {
        $article = Article::create(['title' => 'a']);

        $article->enterState('scheduled', now()->addHour());
        $this->assertTrue($article->inState('scheduled'));

        $this->expectException(CannotChangeState::class);
        $article->enterState('scheduled');
    }

    public function test_toggles_and_inverse_names(): void
    {
        $article = Article::create(['title' => 'a']);

        $article->enterState('featured');
        $this->assertTrue($article->fresh()->is_featured);

        $article->enterState('notFeatured');
        $this->assertFalse($article->fresh()->is_featured);

        $article->setState('featured')->setState('published');
        $this->assertTrue($article->isDirty(['is_featured', 'published_at']), 'setState does not save');
    }

    public function test_highlights_switch_the_flag_and_set_the_window(): void
    {
        $article = Article::create(['title' => 'a']);

        $article->enterState('breaking', until: now()->addHours(6));
        $article->refresh();

        $this->assertTrue($article->inState('breaking'));
        $this->assertTrue($article->breaking_at->equalTo(now()));
        $this->assertTrue($article->breaking_expires->equalTo(now()->addHours(6)));

        $article->leaveState('breaking');
        $article->refresh();

        $this->assertFalse($article->inState('breaking'));
        $this->assertFalse($article->is_breaking, 'leaving switches the flag off');
        $this->assertNotNull($article->breaking_at, 'and keeps the dates for the record');

        // Re-entering after expiry reopens the window.
        $article->forceFill(['is_breaking' => true, 'breaking_expires' => now()->subMinute()])->save();
        $article->enterState('breaking');
        $this->assertTrue($article->fresh()->inState('breaking'));
        $this->assertNull($article->fresh()->breaking_expires);
    }

    public function test_composites_enter_every_part_and_leave_through_the_first_that_can(): void
    {
        $campaign = Campaign::create(['status' => Status::Draft, 'ends_at' => now()->subDay()]);

        $campaign->setState('active')->setState('scheduled', until: now()->addWeek())->save();
        $this->assertTrue($campaign->fresh()->inState('running'));

        $campaign->leaveState('active');
        $this->assertSame(Status::Paused, $campaign->fresh()->status, 'the "otherwise" value');

        $campaign->enterState('active');
        $campaign->leaveState('scheduled');
        $this->assertTrue($campaign->fresh()->inState('ended'));
    }

    public function test_rules_without_a_way_back_refuse_clearly(): void
    {
        $this->expectException(CannotChangeState::class);
        $this->expectExceptionMessage('max_impressions');

        (new Campaign)->setState('withinLimits', false);
    }

    public function test_expiry_changes(): void
    {
        $token = Token::create(['expires_at' => now()->addDay()]);

        $token->enterState('expired');
        $this->assertTrue($token->fresh()->inState('expired'));

        $token->enterState('unexpired');
        $this->assertNull($token->fresh()->expires_at);
    }
}
