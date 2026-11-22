<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

enum MealType: string
{
    case Single = 'single';
    case Sharing = 'sharing';
    case Family = 'family';
    case Kids = 'kids';
    case Corporate = 'corporate';

    /** Arabic name shown in the owner-facing UI. */
    public function label(): string
    {
        return match ($this) {
            self::Single => 'وجبة فردية',
            self::Sharing => 'للمشاركة',
            self::Family => 'عائلي',
            self::Kids => 'أطفال',
            self::Corporate => 'شركات',
        };
    }
}
