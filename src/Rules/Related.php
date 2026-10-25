<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Atannex\Scopable\Registry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * A related row exists, optionally in one of its own states
 * (a post whose category is published). Model checks use the loaded
 * relation, loading it when needed.
 */
final class Related extends Rule
{
    public function __construct(
        public readonly string $relation,
        public readonly ?string $state = null,
    ) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $query->whereHas($this->relation, $this->constraint($now));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $query->whereDoesntHave($this->relation, $this->constraint($now));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $related = $model->getRelationValue($this->relation);
        $related = $related instanceof Collection ? $related->all() : array_filter([$related]);

        foreach ($related as $item) {
            if ($this->state === null || Registry::for($item::class)->passes($item, $this->state, $now)) {
                return true;
            }
        }

        return false;
    }

    public function describe(): string
    {
        return $this->state === null ? "has {$this->relation}" : "has {$this->relation} that is {$this->state}";
    }

    private function constraint(CarbonImmutable $now): ?\Closure
    {
        if ($this->state === null) {
            return null;
        }

        return fn (Builder $q) => Registry::for($q->getModel()::class)->apply($q, $this->state, true, $now);
    }
}
