<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Domain\Metrics\Reports\OrderTypeSales;
use PHPUnit\Framework\TestCase;

class OrderTypeSalesTest extends TestCase
{
    public function test_sums_order_types_by_branch_and_applies_channel_filter(): void
    {
        $rows = [
            (object) ['branch_id' => 1, 'sales_by_type' => '{"delivery":{"orders":2,"net_sales":"90.00"},"takeaway":{"orders":1,"net_sales":"20.00"}}'],
            (object) ['branch_id' => 2, 'sales_by_type' => '{"delivery":{"orders":3,"net_sales":"120.00"}}'],
        ];
        $summary = OrderTypeSales::summarize($rows, [1 => 'A', 2 => 'B'], 'delivery');

        self::assertSame(210.0, $summary['totals']['delivery']['sales']);
        self::assertSame(5, $summary['totals']['delivery']['orders']);
        self::assertCount(2, $summary['rows']);
        self::assertArrayNotHasKey('takeaway', $summary['totals']);
    }
}
