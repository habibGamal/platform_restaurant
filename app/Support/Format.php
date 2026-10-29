<?php

declare(strict_types=1);

namespace App\Support;

final class Format
{
    public static function money(?float $amount, int $decimals = 0): string
    {
        if ($amount === null) {
            return '—';
        }

        return number_format($amount, $decimals, '.', ',').' ج.م';
    }

    public static function percent(?float $ratio, int $decimals = 1): string
    {
        if ($ratio === null) {
            return '—';
        }

        return number_format($ratio * 100, $decimals, '.', ',').'%';
    }

    public static function number(?float $number, int $decimals = 0): string
    {
        if ($number === null) {
            return '—';
        }

        return number_format($number, $decimals, '.', ',');
    }

    public static function growth(?float $growth): string
    {
        if ($growth === null) {
            return '—';
        }

        $roundedPercent = round($growth * 100, 1);

        if ($roundedPercent > 0.0) {
            return '↑ +'.number_format($roundedPercent, 1, '.', ',').'%';
        }

        if ($roundedPercent < 0.0) {
            return '↓ -'.number_format(abs($roundedPercent), 1, '.', ',').'%';
        }

        return '0.0%';
    }
}
