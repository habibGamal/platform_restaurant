<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

enum Season: string
{
    case All = 'all';
    case Winter = 'winter';
    case Summer = 'summer';
    case Ramadan = 'ramadan';
    case Fasting = 'fasting';

    /** Arabic name shown in the owner-facing UI. */
    public function label(): string
    {
        return match ($this) {
            self::All => 'طول السنة',
            self::Winter => 'شتوي',
            self::Summer => 'صيفي',
            self::Ramadan => 'رمضان',
            self::Fasting => 'صيامي',
        };
    }
}
