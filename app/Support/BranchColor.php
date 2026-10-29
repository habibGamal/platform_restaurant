<?php

declare(strict_types=1);

namespace App\Support;

final class BranchColor
{
    /**
     * Accessible, high-contrast 10-colour palette for reports.
     *
     * @var list<string>
     */
    public const array PALETTE = [
        '#10b981', // Emerald
        '#3b82f6', // Blue
        '#f59e0b', // Amber
        '#8b5cf6', // Violet
        '#ec4899', // Pink
        '#06b6d4', // Cyan
        '#f97316', // Orange
        '#6366f1', // Indigo
        '#14b8a6', // Teal
        '#ef4444', // Red
    ];

    public static function for(int $branchId): string
    {
        $index = abs($branchId) % count(self::PALETTE);

        return self::PALETTE[$index];
    }

    /**
     * @return list<string>
     */
    public static function palette(): array
    {
        return self::PALETTE;
    }
}
