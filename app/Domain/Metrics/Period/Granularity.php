<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Period;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum Granularity: string
{
    case day = 'day';
    case week = 'week';
    case month = 'month';

    public static function auto(Period $p): self
    {
        $days = $p->days();

        if ($days <= 31) {
            return self::day;
        }

        if ($days <= 182) {
            return self::week;
        }

        return self::month;
    }

    public function bucketStart(CarbonImmutable $d): CarbonImmutable
    {
        return match ($this) {
            self::day => $d->startOfDay(),
            self::week => $d->startOfWeek(CarbonInterface::SATURDAY)->startOfDay(),
            self::month => $d->startOfMonth()->startOfDay(),
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::day => 'يومي',
            self::week => 'أسبوعي',
            self::month => 'شهري',
        };
    }
}
