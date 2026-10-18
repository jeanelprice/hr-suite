<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Calculs monétaires exacts (jamais de nombres à virgule flottante).
 * Les montants circulent sous forme de texte « 1234.50 » ou de BigDecimal.
 */
final class Money
{
    public static function of(BigDecimal|string|int|float|null $value): BigDecimal
    {
        if ($value instanceof BigDecimal) {
            return $value;
        }
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = number_format($value, 10, '.', '');
        }

        return BigDecimal::of($value);
    }

    /** Arrondi monétaire à 2 décimales (demi vers le haut) */
    public static function round2(BigDecimal|string|int|float|null $value): BigDecimal
    {
        return self::of($value)->toScale(2, RoundingMode::HalfUp);
    }

    /** @param iterable<BigDecimal|string|int|float|null> $values */
    public static function sum(iterable $values): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($values as $v) {
            $total = $total->plus(self::of($v));
        }

        return $total;
    }

    /** Texte à 2 décimales pour la base de données : « 1234.50 » */
    public static function str(BigDecimal|string|int|float|null $value): string
    {
        return (string) self::round2($value);
    }

    /** Format lisible : « 12 345,67 » */
    public static function fmt(BigDecimal|string|int|float|null $value): string
    {
        $n = (string) self::round2($value);
        $negative = str_starts_with($n, '-');
        [$int, $frac] = explode('.', ltrim($n, '-'));
        $int = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $int);

        return ($negative ? '-' : '').$int.','.$frac;
    }

    public static function max(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isGreaterThan($b) ? $a : $b;
    }

    public static function min(BigDecimal $a, BigDecimal $b): BigDecimal
    {
        return $a->isLessThan($b) ? $a : $b;
    }
}
