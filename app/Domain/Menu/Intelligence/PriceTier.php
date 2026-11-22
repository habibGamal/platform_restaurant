<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

enum PriceTier: string
{
    case Single = 'single';
    case Sharing = 'sharing';
    case Family = 'family';

    /** Tier a serving count implies: 4+ is family, 2+ is sharing. */
    public static function forServes(int $servesN): self
    {
        return match (true) {
            $servesN >= 4 => self::Family,
            $servesN >= 2 => self::Sharing,
            default => self::Single,
        };
    }

    /** Arabic name shown in the owner-facing UI. */
    public function label(): string
    {
        return match ($this) {
            self::Single => 'فردي',
            self::Sharing => 'مشاركة',
            self::Family => 'عائلي',
        };
    }
}
