<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Feature;

use Atannex\Scopable\Events\StateChanged;
use Atannex\Scopable\Events\StateEntered;
use Atannex\Scopable\Events\StateLeft;
use Atannex\Scopable\Tests\Fixtures\Article;
use Atannex\Scopable\Tests\Fixtures\Campaign;
use Atannex\Scopable\Tests\Fixtures\Note;
use Atannex\Scopable\Tests\Fixtures\Status;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

final class LifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-17 12:00:00'));
    }

    public function test_default_states_apply_to_every_query_and_can_be_lifted(): void
    {
        Note::create(['published_at' => now()->subDay(), 'is_pinned' => true]);
        Note::create(['published_at' => now()->addDay(), 'is_pinned' => true]);
        Note::create([]);

        $this->assertSame(1, Note::count());
        $this->assertSame(1, Note::pinned()->count());
        $this->assertSame(3, Note::withoutStateDefaults()->count());
        $this->assertSame(3, Note::withoutStateDefaults('published')->count());
        $this->assertSame(1, Note::withoutStateDefaults()->scheduled()->count());
        $this->assertSame(2, Note::withoutStateDefaults()->pinned()->count());
    }

    public function test_saves_fire_entered_and_left_for_tracked_states(): void
    {
        Event::fake([StateEntered::class, StateLeft::class]);

        $article = Article::create(['title' => 'a']);
        Event::assertNotDispatched(StateEntered::class);

        $article->enterState('breaking');
        Event::assertDispatched(StateEntered::class, fn (StateEntered $e) => $e->state === 'breaking' && $e->cause === StateChanged::SAVED && $e->model->is($article));

        $article->title = 'b';
        $article->save();
        Event::assertDispatchedTimes(StateEntered::class, 1);

        $article->leaveState('breaking');
        Event::assertDispatched(StateLeft::class, fn (StateLeft $e) => $e->state === 'breaking' && ! $e->entered());

        // Untracked states stay quiet.
        $article->enterState('featured');
        Event::assertDispatchedTimes(StateEntered::class, 1);
    }

    public function test_new_rows_already_in_a_tracked_state_announce_it(): void
    {
        Event::fake([StateEntered::class]);

        Article::create(['title' => 'a', 'is_breaking' => true, 'breaking_at' => now()]);

        Event::assertDispatched(StateEntered::class, fn ($e) => $e->state === 'breaking');
    }

    public function test_events_wait_for_the_transaction_to_commit(): void
    {
        $seen = [];
        Event::listen(StateEntered::class, function (StateEntered $e) use (&$seen) {
            $seen[] = $e->state;
        });

        DB::transaction(function () use (&$seen) {
            Article::create(['title' => 'a'])->enterState('breaking');
            $this->assertSame([], $seen);
        });

        $this->assertSame(['breaking'], $seen);

        DB::beginTransaction();
        Article::create(['title' => 'b'])->enterState('breaking');
        DB::rollBack();

        $this->assertSame(['breaking'], $seen, 'rolled back, never announced');
    }

    public function test_events_can_be_switched_off(): void
    {
        config(['scopable.events' => false]);
        Event::fake([StateEntered::class]);

        Article::create(['title' => 'a'])->enterState('breaking');

        Event::assertNotDispatched(StateEntered::class);
    }

    public function test_the_sweep_finds_states_the_clock_moved(): void
    {
        config(['scopable.models' => [Campaign::class, Article::class]]);

        $starting = Campaign::create(['status' => Status::Active, 'starts_at' => now()->addMinutes(5)]);
        $ending = Campaign::create(['status' => Status::Active, 'ends_at' => now()->addMinutes(5)]);
        Campaign::create(['status' => Status::Active, 'starts_at' => now()->addDay()]);
        $breaking = Article::create(['title' => 'a', 'is_breaking' => true, 'breaking_at' => now()->subHour(), 'breaking_expires' => now()->addMinutes(3)]);

        $this->artisan('scopable:sweep')->assertSuccessful();

        Event::fake([StateEntered::class, StateLeft::class]);
        $this->travel(10)->minutes();

        $this->artisan('scopable:sweep')->expectsOutputToContain('5 state changes')->assertSuccessful();

        Event::assertDispatched(StateEntered::class, fn ($e) => $e->model->is($starting) && $e->state === 'scheduled' && $e->cause === StateChanged::TIME);
        Event::assertDispatched(StateEntered::class, fn ($e) => $e->model->is($starting) && $e->state === 'running');
        Event::assertDispatched(StateLeft::class, fn ($e) => $e->model->is($ending) && $e->state === 'scheduled');
        Event::assertDispatched(StateLeft::class, fn ($e) => $e->model->is($ending) && $e->state === 'running');
        Event::assertDispatched(StateLeft::class, fn ($e) => $e->model->is($breaking) && $e->state === 'breaking');

        // Nothing new since: a second sweep is silent.
        $this->artisan('scopable:sweep')->expectsOutputToContain('0 state changes')->assertSuccessful();
    }

    public function test_the_sweep_can_look_back_from_a_given_moment(): void
    {
        Campaign::create(['status' => Status::Active, 'starts_at' => now()->subHours(2)]);
        Event::fake([StateEntered::class]);

        $this->artisan('scopable:sweep', ['--model' => [Campaign::class], '--since' => now()->subHours(3)->toIso8601String()])->assertSuccessful();

        Event::assertDispatchedTimes(StateEntered::class, 2); // scheduled + running
    }

    public function test_show_lists_states_dates_filters_and_sorts(): void
    {
        $this->assertSame(0, Artisan::call('scopable:show', ['model' => Article::class, '--sql' => true]));
        $output = Artisan::output();

        foreach (['published_at is set and <= now', 'notPublished, unpublished', 'live', 'tracked', 'flagged', 'order asc, id desc', 'createdToday()', 'select * from "articles"'] as $text) {
            $this->assertStringContainsString($text, $output);
        }

        $this->artisan('scopable:show', ['model' => 'Nope'])->assertFailed();
    }
}
