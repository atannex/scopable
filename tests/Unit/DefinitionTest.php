<?php

declare(strict_types=1);

namespace Atannex\Scopable\Tests\Unit;

use Atannex\Scopable\Concerns\HasScopes;
use Atannex\Scopable\Exceptions\InvalidDefinition;
use Atannex\Scopable\Registry;
use Atannex\Scopable\Rules\Rule;
use Atannex\Scopable\Scopes;
use Atannex\Scopable\Tests\TestCase;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;

final class DefinitionTest extends TestCase
{
    /**
     * @return array<string, array{0: Closure(Scopes): mixed, 1: string}>
     */
    public static function invalid(): array
    {
        return [
            'Builder method' => [fn (Scopes $s) => $s->toggle('count'), 'it is a method of'],
            'Eloquent method' => [fn (Scopes $s) => $s->toggle('latest'), 'it is a method of'],
            'Model method' => [fn (Scopes $s) => $s->toggle('fresh'), 'it is a method of'],
            'event method' => [fn (Scopes $s) => $s->toggle('created'), 'it is a method of'],
            'existing scope' => [fn (Scopes $s) => $s->toggle('legacy'), 'already a scope method'],
            'duplicate' => [fn (Scopes $s) => [$s->toggle('active'), $s->toggle('enabled')->alias('active')], 'already used'],
            'inverse clash' => [fn (Scopes $s) => [$s->toggle('active'), $s->toggle('notActive')], 'already used'],
            'filter clash' => [fn (Scopes $s) => [$s->toggle('active'), $s->filter('active', 'x')], 'already used'],
            'bad name' => [fn (Scopes $s) => $s->toggle('is-active'), 'not a valid method name'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_names_that_would_shadow_or_collide_are_refused(Closure $define, string $message): void
    {
        DefinitionModel::$define = $define;
        Registry::flush();

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage($message);

        Registry::for(DefinitionModel::class);
    }

    public function test_state_loops_are_refused(): void
    {
        DefinitionModel::$define = fn (Scopes $s) => [
            $s->state('a', Rule::state('b')),
            $s->state('b', Rule::state('a')),
        ];
        Registry::flush();

        $this->expectException(InvalidDefinition::class);
        $this->expectExceptionMessage('loop');

        (new DefinitionModel)->inState('a');
    }

    public function test_definitions_are_sealed(): void
    {
        DefinitionModel::$define = fn (Scopes $s) => $s->toggle('active');
        Registry::flush();

        $this->expectException(\LogicException::class);

        Registry::for(DefinitionModel::class)->toggle('late');
    }

    public function test_compiled_once_per_class(): void
    {
        DefinitionModel::$define = fn (Scopes $s) => $s->toggle('active');
        Registry::flush();

        $this->assertSame(Registry::for(DefinitionModel::class), Registry::for(DefinitionModel::class));
        $this->assertContains('notActive', Registry::for(DefinitionModel::class)->methods());
    }

    public function test_automatic_dates_follow_the_model_and_config(): void
    {
        DefinitionModel::$define = fn () => null;
        Registry::flush();
        $this->assertSame(['created' => 'created_at', 'updated' => 'updated_at'], Registry::for(DefinitionModel::class)->dates());

        config(['scopable.dates.updated' => false]);
        Registry::flush();
        $this->assertSame(['created' => 'created_at'], Registry::for(DefinitionModel::class)->dates());
    }

    public function test_rules_describe_themselves(): void
    {
        $rule = Rule::all(
            Rule::toggle('is_active'),
            Rule::any(Rule::reached('published_at'), Rule::equals('status', ['a', 'b'])),
            Rule::window('starts_at', 'ends_at'),
            Rule::under('used', 'cap'),
        );

        $this->assertSame(
            "is_active is true and (published_at is set and <= now or status in ['a', 'b']) and (starts_at is null or <= now) and (ends_at is null or > now) and (cap is null or used < cap)",
            $rule->describe(),
        );
    }
}

class DefinitionModel extends Model
{
    use HasScopes;

    protected $table = 'notes';

    public static ?Closure $define = null;

    public static function defineScopes(Scopes $scopes): void
    {
        (static::$define)($scopes);
    }

    public function scopeLegacy(Builder $query): Builder
    {
        return $query;
    }
}
