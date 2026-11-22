<?php

declare(strict_types=1);

namespace App\Domain\Menu\Intelligence;

/** What a recipe component is, so profiles can say what an item contains. */
enum FoodClass: string
{
    case Meat = 'meat';
    case Chicken = 'chicken';
    case Seafood = 'seafood';
    case Rice = 'rice';
    case Bread = 'bread';
    case Salad = 'salad';
    case Sauce = 'sauce';
    case Soup = 'soup';
    case Vegetable = 'vegetable';
    case Drink = 'drink';
    case Dessert = 'dessert';
    case Packaging = 'packaging';
    case Cutlery = 'cutlery';
    case Other = 'other';

    /** Arabic name shown in the owner-facing UI. */
    public function label(): string
    {
        return match ($this) {
            self::Meat => 'لحمة',
            self::Chicken => 'فراخ',
            self::Seafood => 'سي فود',
            self::Rice => 'رز',
            self::Bread => 'عيش',
            self::Salad => 'سلطة',
            self::Sauce => 'صوص',
            self::Soup => 'شوربة',
            self::Vegetable => 'خضار',
            self::Drink => 'مشروب',
            self::Dessert => 'حلو',
            self::Packaging => 'تغليف',
            self::Cutlery => 'أدوات أكل',
            self::Other => 'حاجة تانية',
        };
    }

    /** Packaging and cutlery are recipe lines, not food, so they never count toward "contains". */
    public function isFood(): bool
    {
        return ! in_array($this, [self::Packaging, self::Cutlery], true);
    }

    public function isProtein(): bool
    {
        return in_array($this, [self::Meat, self::Chicken, self::Seafood], true);
    }
}
