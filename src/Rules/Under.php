<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A counter below its cap: impressions < max_impressions, spend < budget.
 * A NULL cap is unlimited; a NULL counter counts as 0.
 */
final class Under extends Rule
{
    public function __construct(
        public readonly string $used,
        public readonly string $cap,
    ) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        $cap = $this->qualify($query, $this->cap);

        $query->where(fn (Builder $q) => $q->whereNull($cap)->orWhereRaw($this->comparison($q, '<')));
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $query->whereNotNull($this->qualify($query, $this->cap))->whereRaw($this->comparison($query, '>='));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        $cap = $model->getAttribute($this->cap);

        return $cap === null || (float) ($model->getAttribute($this->used) ?? 0) < (float) $cap;
    }

    public function describe(): string
    {
        return "({$this->cap} is null or {$this->used} < {$this->cap})";
    }

    private function comparison(Builder $query, string $operator): string
    {
        $grammar = $query->getQuery()->getGrammar();

        return 'COALESCE('.$grammar->wrap($this->qualify($query, $this->used)).', 0) '.$operator.' '.$grammar->wrap($this->qualify($query, $this->cap));
    }
}
