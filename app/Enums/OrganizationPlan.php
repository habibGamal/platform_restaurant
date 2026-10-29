<?php

namespace App\Enums;

enum OrganizationPlan: string
{
    case BASIC = 'basic';
    case PRO = 'pro';

    public function label(): string
    {
        return match ($this) {
            self::BASIC => 'أساسي',
            self::PRO => 'احترافي',
        };
    }
}
