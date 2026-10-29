<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

enum ComparisonMode: string
{
    case previous = 'previous';
    case same_weekday_last_week = 'same_weekday_last_week';
    case same_period_last_year = 'same_period_last_year';
    case none = 'none';

    public function label(): string
    {
        return match ($this) {
            self::previous => 'الفترة السابقة',
            self::same_weekday_last_week => 'نفس الأيام الأسبوع الماضي',
            self::same_period_last_year => 'نفس الفترة العام الماضي',
            self::none => 'بدون مقارنة',
        };
    }
}
