<?php

declare(strict_types=1);

namespace Atannex\Scopable\Support;

use BackedEnum;
use DateTimeInterface;
use Stringable;
use UnitEnum;

/**
 * Comparable scalars from enum casts (native, BenSampo-style objects with a
 * public `value`), so SQL bindings and PHP checks see the same thing.
 */
final class Values
{
    public static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value,
            is_object($value) && property_exists($value, 'value') => $value->value,
            $value instanceof Stringable => (string) $value,
            default => $value,
        };
    }

    /**
     * @return list<mixed>
     */
    public static function list(mixed $values): array
    {
        $values = is_array($values) ? $values : [$values];

        return array_values(array_map(self::normalize(...), $values));
    }

    /**
     * Loose-but-safe equality: "1" == 1, true == 1, enum == its value.
     */
    public static function same(mixed $a, mixed $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return (string) $a === (string) $b;
    }

    /**
     * Compare two values with a SQL operator; dates and numbers compare as such.
     */
    public static function compare(mixed $a, string $operator, mixed $b): bool
    {
        $a = self::normalize($a);
        $b = self::normalize($b);

        if ($a === null || $b === null) {
            return false;
        }

        if ($a instanceof DateTimeInterface || $b instanceof DateTimeInterface) {
            $a = (float) Clock::value($a)?->format('U.u');
            $b = (float) Clock::value($b)?->format('U.u');
        } elseif (is_numeric($a) && is_numeric($b)) {
            $a = (float) $a;
            $b = (float) $b;
        } else {
            $cmp = strcmp((string) $a, (string) $b);
            $a = $cmp;
            $b = 0;
        }

        return match ($operator) {
            '=' => $a == $b,
            '!=', '<>' => $a != $b,
            '<' => $a < $b,
            '<=' => $a <= $b,
            '>' => $a > $b,
            '>=' => $a >= $b,
        };
    }

    public static function inverseOperator(string $operator): string
    {
        return match ($operator) {
            '=' => '!=',
            '!=', '<>' => '=',
            '<' => '>=',
            '<=' => '>',
            '>' => '<=',
            '>=' => '<',
        };
    }

    public static function describe(mixed $value): string
    {
        $value = self::normalize($value);

        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => '['.implode(', ', array_map(self::describe(...), $value)).']',
            is_string($value) => "'{$value}'",
            default => (string) $value,
        };
    }
}
