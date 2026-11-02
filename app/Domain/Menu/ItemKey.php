<?php

declare(strict_types=1);

namespace App\Domain\Menu;

final class ItemKey
{
    /** Org-wide item identity, shared with ProductPerformanceReport. */
    public const SQL = 'COALESCE(product_ref, product_uuid)';

    public static function normalizeCategory(string $name): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name);

        return mb_strtolower($collapsed);
    }
}
