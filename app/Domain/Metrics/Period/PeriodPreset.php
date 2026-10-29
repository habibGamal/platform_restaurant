<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

enum PeriodPreset: string
{
    case today = 'today';
    case yesterday = 'yesterday';
    case this_week = 'this_week';
    case last_week = 'last_week';
    case this_month = 'this_month';
    case last_month = 'last_month';
    case this_quarter = 'this_quarter';
    case this_year = 'this_year';
    case last_30_days = 'last_30_days';
    case last_8_weeks = 'last_8_weeks';
    case custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::today => 'اليوم',
            self::yesterday => 'أمس',
            self::this_week => 'هذا الأسبوع',
            self::last_week => 'الأسبوع الماضي',
            self::this_month => 'هذا الشهر',
            self::last_month => 'الشهر الماضي',
            self::this_quarter => 'هذا الربع',
            self::this_year => 'هذا العام',
            self::last_30_days => 'آخر 30 يوم',
            self::last_8_weeks => 'آخر 8 أسابيع',
            self::custom => 'فترة مخصصة',
        };
    }
}
