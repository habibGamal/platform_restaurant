<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

/** Single trust rule for intelligence labels (component classes, category and item profiles). */
final class LabelTrust
{
    public static function isTrusted(string $source, float $confidence): bool
    {
        return match ($source) {
            'owner' => true,
            'auto', 'rule' => $confidence >= (float) config('menu.intelligence.trusted_confidence'),
            default => false,
        };
    }
}
