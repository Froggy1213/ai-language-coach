<?php

namespace App\GraphQL\Types;

use BackedEnum;
use GraphQL\Type\Definition\EnumType;
use InvalidArgumentException;

/**
 * GraphQL enum backed by a native PHP enum.
 *
 * The value name on the wire is the case's backed value (`active`, `A1`) — the
 * contract in plan §4 — while the internal value is the enum case itself, so an
 * Eloquent enum cast round-trips without a mapping layer.
 *
 * Instances are registered on Lighthouse's TypeRegistry; the SDL references the
 * enum by name and must not declare it.
 *
 * @template TEnum of BackedEnum
 */
final class NativeEnumType extends EnumType
{
    /**
     * @param  class-string<TEnum>  $enumClass
     * @param  string|null  $name  Defaults to the basename of the enum class.
     */
    public function __construct(string $enumClass, ?string $name = null)
    {
        if (! is_a($enumClass, BackedEnum::class, true)) {
            throw new InvalidArgumentException("[{$enumClass}] is not a backed enum.");
        }

        $values = [];

        foreach ($enumClass::cases() as $case) {
            // EnumType reads the array key as the value name and keeps the
            // `value` entry as the internal value handed to resolvers.
            $values[(string) $case->value] = ['value' => $case];
        }

        parent::__construct([
            'name' => $name ?? class_basename($enumClass),
            'values' => $values,
        ]);
    }
}
