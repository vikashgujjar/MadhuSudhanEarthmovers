<?php

namespace App\Support;

/**
 * Exact money arithmetic. Amounts are decimal strings ("1234.50") in the
 * database and in PHP; calculations are done in integer paise so that no
 * floating-point rounding ever enters a total.
 */
final class Money
{
    public const ZERO = '0.00';

    /** Convert a decimal value to integer paise, rounding half up. */
    public static function toPaise(string|int|float|null $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * 100;
        }
        $text = is_float($value) ? sprintf('%.6F', $value) : trim($value);
        if (! preg_match('/^([+-])?(\d*)(?:\.(\d*))?$/', $text, $m) || ($m[2] === '' && ($m[3] ?? '') === '')) {
            throw new \InvalidArgumentException("Not a decimal amount: {$value}");
        }
        $negative = ($m[1] ?? '') === '-';
        $whole = (int) ($m[2] === '' ? '0' : $m[2]);
        $fraction = str_pad($m[3] ?? '', 3, '0');
        $paise = $whole * 100 + (int) substr($fraction, 0, 2);
        if ((int) $fraction[2] >= 5) {
            $paise++;
        }

        return $negative ? -$paise : $paise;
    }

    public static function fromPaise(int $paise): string
    {
        $sign = $paise < 0 ? '-' : '';
        $abs = abs($paise);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Normalise to a 2-decimal string. */
    public static function of(string|int|float|null $value): string
    {
        return self::fromPaise(self::toPaise($value));
    }

    public static function add(string|int|float|null ...$values): string
    {
        $total = 0;
        foreach ($values as $v) {
            $total += self::toPaise($v);
        }

        return self::fromPaise($total);
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromPaise(self::toPaise($a) - self::toPaise($b));
    }

    /** quantity × rate, both with up to 2 decimals, rounded half up to paise. */
    public static function multiply(string|int|float|null $quantity, string|int|float|null $rate): string
    {
        $product = self::toPaise($quantity) * self::toPaise($rate); // scale 10^4
        $sign = $product < 0 ? -1 : 1;

        return self::fromPaise($sign * intdiv(abs($product) + 50, 100));
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toPaise($a) <=> self::toPaise($b);
    }

    public static function isPositive(string|int|float|null $value): bool
    {
        return self::toPaise($value) > 0;
    }

    public static function isNegative(string|int|float|null $value): bool
    {
        return self::toPaise($value) < 0;
    }

    public static function isZero(string|int|float|null $value): bool
    {
        return self::toPaise($value) === 0;
    }

    /** @param iterable<string|int|float|null> $values */
    public static function sum(iterable $values): string
    {
        $total = 0;
        foreach ($values as $v) {
            $total += self::toPaise($v);
        }

        return self::fromPaise($total);
    }

    /** part / whole × 100 with one decimal (half up), or null when whole is zero. */
    public static function percent(string|int|float|null $part, string|int|float|null $whole): ?string
    {
        $w = self::toPaise($whole);
        if ($w === 0) {
            return null;
        }
        $p = self::toPaise($part);
        $tenths = $p * 1000 / $w;
        $rounded = $tenths < 0 ? -floor(-$tenths + 0.5) : floor($tenths + 0.5);

        return number_format($rounded / 10, 1, '.', '');
    }

    public static function toFloat(string|int|float|null $value): float
    {
        return self::toPaise($value) / 100;
    }

    /** Indian digit grouping: 12,34,567.00 */
    public static function inr(string|int|float|null $value, int $decimals = 2): string
    {
        $paise = self::toPaise($value ?? 0);
        $negative = $paise < 0;
        $abs = abs($paise);
        if ($decimals === 0) {
            $whole = (string) intdiv($abs + 50, 100);
            $fraction = '';
        } else {
            $whole = (string) intdiv($abs, 100);
            $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        }
        if (strlen($whole) > 3) {
            $head = substr($whole, 0, -3);
            $tail = substr($whole, -3);
            $groups = [];
            while (strlen($head) > 2) {
                array_unshift($groups, substr($head, -2));
                $head = substr($head, 0, -2);
            }
            if ($head !== '') {
                array_unshift($groups, $head);
            }
            $whole = implode(',', [...$groups, $tail]);
        }
        $result = $fraction !== '' ? "{$whole}.{$fraction}" : $whole;

        return $negative && $abs > 0 ? "-{$result}" : $result;
    }
}
