<?php

namespace App\Support;

final class Format
{
    public static function value(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            \is_array($value) => json_encode($value, \JSON_THROW_ON_ERROR),
            \is_scalar($value) => (string) $value,
            default => get_debug_type($value),
        };
    }
}
