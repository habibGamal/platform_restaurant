<?php

declare(strict_types=1);

namespace App\Domain\Menu;

/**
 * Behavioural facts about one menu item over a period, derived from completed orders only.
 * No role judgement lives here; see RoleSignals::hardRole() for the deterministic roles.
 */
final readonly class ItemSignals
{
    public function __construct(
        public string $itemKey,
        public string $name,
        public ?string $categoryName,
        public ?string $productType,
        public float $avgPrice,
        public float $avgUnitCost,
        public int $ordersWith,
        public int $ordersAsOnlyLine,
        /** 1 - ordersAsOnlyLine / ordersWith: share of its orders where it travels with another item. */
        public float $attachRate,
        public float $avgQtyPerOrder,
        /** avgPrice / median avgPrice of manufactured items (0 when the median is 0). */
        public float $priceVsMedian,
    ) {}
}
