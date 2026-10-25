<?php

declare(strict_types=1);

namespace Atannex\Scopable\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Any query. Give $passes to check loaded models in PHP; without it a saved
 * model is checked with a query by key. Give $inverse when the condition can
 * be NULL, or NOT (…) will miss those rows.
 */
final class Callback extends Rule
{
    public function __construct(
        private readonly Closure $query,
        private readonly ?Closure $check = null,
        private readonly ?Closure $inverse = null,
        private readonly string $description = 'custom condition',
    ) {}

    public function apply(Builder $query, CarbonImmutable $now): void
    {
        ($this->query)($query, $now);
    }

    public function applyInverse(Builder $query, CarbonImmutable $now): void
    {
        $this->inverse !== null
            ? ($this->inverse)($query, $now)
            : $query->whereNot(fn (Builder $q) => ($this->query)($q, $now));
    }

    public function passes(Model $model, CarbonImmutable $now): bool
    {
        if ($this->check !== null) {
            return (bool) ($this->check)($model, $now);
        }

        if (! $model->exists) {
            throw new LogicException("[{$this->description}] cannot check an unsaved model without a PHP check; pass \$passes to Rule::callback().");
        }

        $query = $model->newQueryWithoutScopes()->whereKey($model->getKey());
        $query->where(fn (Builder $q) => $this->apply($q, $now));

        return $query->exists();
    }

    public function describe(): string
    {
        return $this->description;
    }
}
