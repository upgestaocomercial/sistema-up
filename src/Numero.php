<?php
declare(strict_types=1);

namespace Up;

final class Numero
{
    /** Aceita 1234.56, "1.234,56", "1234,56", "R$ 1.234,56", "5%" */
    public static function paraFloat(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }
        $s = trim(str_replace(['R$', '%', ' ', "\u{00A0}"], '', $v));
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : null;
    }

    public static function dinheiro(float $v): float
    {
        return round($v + 0.0, 2);
    }
}
