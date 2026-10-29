<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Egyptian week: 0 = Saturday … 6 = Friday. Used by every weekday column (wh_orders.weekday, agg_*.weekday). */
final class Weekday
{
    public const LABELS = ['السبت', 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة'];

    public static function of(CarbonInterface $date): int
    {
        // Carbon: 0 = Sunday … 6 = Saturday  →  Saturday 0, Sunday 1, …, Friday 6
        return ($date->dayOfWeek + 1) % 7;
    }

    public static function label(int $weekday): string
    {
        return self::LABELS[$weekday];
    }
}
