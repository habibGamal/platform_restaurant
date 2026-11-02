<?php

declare(strict_types=1);

namespace App\Domain\Menu;

enum ItemRole: string
{
    case Main = 'main';
    case Side = 'side';
    case Drink = 'drink';
    case Dessert = 'dessert';
    case Addon = 'addon';
    case Combo = 'combo';
    case Adjustment = 'adjustment';
    case Raw = 'raw';

    /** Whether items of this role may appear in marketing focus lists. */
    public function isMarketable(): bool
    {
        return match ($this) {
            self::Main, self::Side, self::Dessert, self::Combo => true,
            default => false,
        };
    }

    /** Whether items of this role may accompany a main inside a combo. */
    public function isComboCompanion(): bool
    {
        return match ($this) {
            self::Side, self::Drink, self::Dessert => true,
            default => false,
        };
    }
}
