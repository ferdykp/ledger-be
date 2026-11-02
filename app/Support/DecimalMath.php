<?php

namespace App\Support;

use InvalidArgumentException;

/** Decimal-safe helpers for financial and quantity calculations. */
final class DecimalMath
{
    public static function add(string|int $a, string|int $b, int $scale = 2): string
    {
        return bcadd((string) $a, (string) $b, $scale);
    }

    public static function subtract(string|int $a, string|int $b, int $scale = 2): string
    {
        return bcsub((string) $a, (string) $b, $scale);
    }

    public static function multiply(string|int $a, string|int $b, int $scale = 2): string
    {
        return bcmul((string) $a, (string) $b, $scale);
    }

    public static function divide(string|int $a, string|int $b, int $scale = 2): string
    {
        if (bccomp((string) $b, '0', 8) === 0) {
            throw new InvalidArgumentException('Pembagi tidak boleh nol.');
        }

        return bcdiv((string) $a, (string) $b, $scale);
    }

    public static function round(string|int $value, int $scale = 2): string
    {
        // Add half of the last retained unit at one extra decimal place,
        // then truncate to the requested precision (bc math does not round).
        $offset = $scale === 0 ? '0.5' : '0.'.str_repeat('0', $scale).'5';
        return bcadd(bcadd((string) $value, $offset, $scale + 1), '0', $scale);
    }

    public static function compare(string|int $a, string|int $b, int $scale = 2): int
    {
        return bccomp((string) $a, (string) $b, $scale);
    }

    public static function quantityValue(string|int $quantity, string|int $unitPrice, int $quantityScale = 8): string
    {
        // Round half-up to the currency precision without passing through float.
        $raw = bcmul((string) $quantity, (string) $unitPrice, $quantityScale + 2);
        return bcadd(bcadd($raw, '0.005', 3), '0', 2);
    }
}
