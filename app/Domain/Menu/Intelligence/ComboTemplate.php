<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

/** Combo shapes; slot definitions live in config('menu.templates'). */
enum ComboTemplate: string
{
    case SoloMeal = 'solo_meal';
    case FamilyFeast = 'family_feast';
    case SharingBox = 'sharing_box';

    /** Arabic name shown in the owner-facing UI. */
    public function label(): string
    {
        return match ($this) {
            self::SoloMeal => 'وجبة فردية',
            self::FamilyFeast => 'عزومة عائلية',
            self::SharingBox => 'بوكس مشاركة',
        };
    }
}
