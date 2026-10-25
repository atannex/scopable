<?php

declare(strict_types=1);

namespace Atannex\Scopable;

use Atannex\Scopable\Rules\Rule;
use Illuminate\Support\Str;

/**
 * A named state of a model ("published", "breaking", "running") and the rule
 * behind it. Configured fluently from Scopes; read-only once the model's
 * definitions are sealed.
 */
final class State
{
    /** @var list<string> */
    private array $inverses = [];

    /** @var list<string> */
    private array $aliases = [];

    private ?string $label = null;

    private bool $default = false;

    private bool $tracked = false;

    public function __construct(
        public readonly string $name,
        public readonly Rule $rule,
    ) {}

    /**
     * Extra name(s) for the opposite state ("inactive", "unpublished").
     * "not{Name}" is always available.
     */
    public function inverse(string ...$names): self
    {
        array_push($this->inverses, ...$names);

        return $this;
    }

    /**
     * Extra name(s) for this state ("live" for "running").
     */
    public function alias(string ...$names): self
    {
        array_push($this->aliases, ...$names);

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Apply to every query on the model, like soft deletes. Lift with
     * ->withoutStateDefaults('name') or ->withoutStateDefaults().
     */
    public function byDefault(bool $default = true): self
    {
        $this->default = $default;

        return $this;
    }

    /**
     * Fire StateEntered / StateLeft when this state changes, on save and
     * (with scopable:sweep) when the clock moves it.
     */
    public function tracked(bool $tracked = true): self
    {
        $this->tracked = $tracked;

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    /** @return list<string> */
    public function inverses(): array
    {
        return array_values(array_unique(['not'.Str::studly($this->name), ...$this->inverses]));
    }

    /** @return list<string> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    public function getLabel(): string
    {
        return $this->label ?? Str::headline($this->name);
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function isTracked(): bool
    {
        return $this->tracked;
    }

    public function describe(): string
    {
        return $this->rule->describe();
    }
}
