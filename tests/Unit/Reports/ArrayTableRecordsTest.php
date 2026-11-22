<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Filament\App\Widgets\Reports\ArrayTableRecords;
use PHPUnit\Framework\TestCase;

class ArrayTableRecordsTest extends TestCase
{
    public function test_filters_sorts_numeric_values_and_paginates_without_losing_keys(): void
    {
        $rows = [
            'a' => ['name' => 'Alex', 'sales' => '<b>1,200.00</b>'],
            'b' => ['name' => 'Alex East', 'sales' => '50.00'],
            'c' => ['name' => 'Cairo', 'sales' => '1.00'],
        ];

        $result = ArrayTableRecords::paginate($rows, ['name' => 'Alex'], null, 'sales', 'asc', 1, 1);

        self::assertSame(2, $result->total());
        self::assertSame(['b'], array_keys($result->items()));
    }

    public function test_global_search_ignores_html_and_finds_text(): void
    {
        $result = ArrayTableRecords::paginate(['a' => ['name' => '<b>Cairo</b>']], [], 'cairo', null, null, 1, 10);
        self::assertSame(1, $result->total());
    }

    public function test_sorts_formatted_growth_and_html_cells_by_primary_numeric_value(): void
    {
        $rows = [
            'a' => ['growth' => '↑ +12.0%', 'sales' => '<div>1,200.00</div><div>+5.0%</div>'],
            'b' => ['growth' => '↓ -4.0%', 'sales' => '<div>50.00</div><div>+10.0%</div>'],
        ];
        self::assertSame(['b', 'a'], array_keys(ArrayTableRecords::paginate($rows, [], null, 'growth', 'asc', 1, 10)->items()));
        self::assertSame(['b', 'a'], array_keys(ArrayTableRecords::paginate($rows, [], null, 'sales', 'asc', 1, 10)->items()));
    }
}
