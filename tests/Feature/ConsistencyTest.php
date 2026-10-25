<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Feature;

use Atannex\Scopable\Tests\Fixtures\Article;
use Atannex\Scopable\Tests\Fixtures\Campaign;
use Atannex\Scopable\Tests\Fixtures\Category;
use Atannex\Scopable\Tests\Fixtures\Status;
use Atannex\Scopable\Tests\Fixtures\Token;
use Atannex\Scopable\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The guarantee the package rests on: for every state, at every moment,
 * the query and the model check agree, and the state and its inverse
 * split the table in two with nothing lost (NULLs included).
 */
final class ConsistencyTest extends TestCase
{
    private const BASE = '2026-06-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::BASE));
        $this->seedRows();
    }

    /**
     * @return array<string, array{0: class-string<Model>}>
     */
    public static function models(): array
    {
        return ['articles' => [Article::class], 'campaigns' => [Campaign::class], 'tokens' => [Token::class]];
    }

    #[DataProvider('models')]
    public function test_query_and_model_checks_agree_at_every_moment(string $class): void
    {
        $base = CarbonImmutable::parse(self::BASE);
        $moments = [
            $base->subYears(2), $base->subDays(3), $base->subHour(), $base->subSecond(),
            $base, $base->addSecond(), $base->addHour(), $base->addDays(3), $base->addYears(2),
        ];

        $rows = $class::query()->with($class === Article::class ? ['category'] : [])->get();
        $this->assertGreaterThan(5, $rows->count());

        foreach ($class::scopeDefinitions()->states() as $name => $state) {
            $everIn = $everOut = false;

            foreach ($moments as $at) {
                $in = $class::query()->whereState($name, at: $at)->pluck('id')->sort()->values()->all();
                $out = $class::query()->whereNotState($name, $at)->pluck('id')->sort()->values()->all();
                $expected = $rows->filter(fn (Model $m) => $m->inState($name, $at))->pluck('id')->sort()->values()->all();

                $context = "{$class} [{$name}] at {$at->toDateTimeString()}";

                $this->assertSame($expected, $in, "SQL and PHP disagree for {$context}");
                $this->assertSame([], array_values(array_intersect($in, $out)), "state and inverse overlap for {$context}");
                $this->assertCount($rows->count(), array_merge($in, $out), "state and inverse lose rows for {$context}");

                $everIn = $everIn || $in !== [];
                $everOut = $everOut || $out !== [];
            }

            $this->assertTrue($everIn && $everOut, "{$class} [{$name}] is never split by the seed data, so the test proves nothing for it");
        }
    }

    private function seedRows(): void
    {
        $now = CarbonImmutable::parse(self::BASE);

        $visible = Category::create(['name' => 'News', 'is_visible' => true]);
        $hidden = Category::create(['name' => 'Hidden', 'is_visible' => false]);
        $unset = Category::create(['name' => 'Unset', 'is_visible' => null]);

        $dates = [null, $now->subYears(3), $now->subDays(1), $now, $now->addMinutes(30), $now->addDays(10)];
        $toggles = [null, false, true];
        $categories = [null, $visible->id, $hidden->id, $unset->id];

        $i = 0;
        foreach ($dates as $published) {
            foreach ($toggles as $flag) {
                $article = Article::create([
                    'title' => 'A'.$i,
                    'category_id' => $categories[$i % 4],
                    'flag' => [null, 'published', 'draft'][$i % 3],
                    'is_featured' => $toggles[($i + 1) % 3],
                    'published_at' => $published,
                    'is_breaking' => (bool) $flag,
                    'breaking_at' => $dates[($i + 2) % 6],
                    'breaking_expires' => $dates[($i + 3) % 6],
                    'order' => $i % 4,
                ]);
                $article->forceFill(['updated_at' => $dates[($i + 4) % 6] ?? $now])->saveQuietly();
                $i++;
            }
        }

        $statuses = [null, Status::Active, Status::Paused, Status::Draft];
        $limits = [[null, null], [5, 10], [10, 10], [null, 3], [0, null]];

        $i = 0;
        foreach ($dates as $start) {
            foreach ($dates as $end) {
                [$used, $cap] = $limits[$i % 5];
                Campaign::create([
                    'status' => $statuses[$i % 4],
                    'starts_at' => $start,
                    'ends_at' => $end,
                    'impressions' => $used,
                    'max_impressions' => $cap,
                    'spend' => [null, 10, 999.99, 1000, 5000][($i + 2) % 5],
                    'budget' => [null, 1000, 2000, 500, 999][$i % 5],
                ]);
                $i++;
            }
        }

        foreach ($dates as $index => $expires) {
            Token::create(['expires_at' => $expires, 'last_used_at' => $dates[($index + 1) % 6]]);
        }
    }
}
