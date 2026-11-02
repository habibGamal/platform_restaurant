<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Concerns\CachesToolResults;
use App\Ai\Tools\Concerns\ExtractsKpiValues;
use App\Ai\Tools\Concerns\FormatsToolResult;
use App\Ai\Tools\Concerns\UsesReportFilters;
use App\Domain\Metrics\KpiValues;
use App\Domain\Metrics\Period\PeriodPreset;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ToolInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_uses_report_filters_schema_and_mapping(): void
    {
        $dummy = new class($this->fixture->owner)
        {
            use UsesReportFilters;

            public function __construct(public User $user) {}
        };

        // 1. Schema generation
        $schema = new JsonSchemaTypeFactory;
        $properties = $dummy->reportFilterSchema($schema);

        $this->assertArrayHasKey('preset', $properties);
        $this->assertArrayHasKey('from', $properties);
        $this->assertArrayHasKey('to', $properties);
        $this->assertArrayHasKey('compare', $properties);
        $this->assertArrayHasKey('branches', $properties);
        $this->assertArrayHasKey('restaurant', $properties);
        $this->assertArrayHasKey('set', $properties);
        $this->assertArrayHasKey('like_for_like', $properties);
        $this->assertArrayHasKey('granularity', $properties);

        // 2. Mapping like_for_like to lfl
        $filters = $dummy->reportFiltersFromArgs([
            'preset' => 'custom',
            'from' => '2026-10-01',
            'to' => '2026-10-07',
            'like_for_like' => true,
        ]);

        $this->assertTrue($filters->likeForLike);
        $this->assertSame(PeriodPreset::custom, $filters->preset);
        $this->assertSame('2026-10-01', $filters->period->from->toDateString());
        $this->assertSame('2026-10-07', $filters->period->to->toDateString());

        // 3. Timezone fallback
        $userWithoutOrg = User::create([
            'name' => 'Standalone User',
            'email' => 'standalone@example.com',
            'password' => bcrypt('password'),
        ]);
        $standaloneDummy = new class($userWithoutOrg)
        {
            use UsesReportFilters;

            public function __construct(public User $user) {}
        };
        $this->assertSame(config('app.timezone', 'Africa/Cairo'), $standaloneDummy->userTimezone());
    }

    public function test_shared_filter_rules_reject_missing_or_reversed_custom_dates(): void
    {
        $dummy = new class($this->fixture->owner)
        {
            use UsesReportFilters;

            public function __construct(public User $user) {}
        };

        foreach ([
            ['preset' => 'custom'],
            ['preset' => 'custom', 'from' => '2026-10-03'],
            ['preset' => 'custom', 'from' => null, 'to' => null],
            ['preset' => 'custom', 'from' => '2026-10-16', 'to' => '2026-10-03'],
        ] as $args) {
            $this->assertTrue(Validator::make($args, $dummy->reportFilterRules())->fails());
        }
        $this->assertFalse(Validator::make(['preset' => 'today'], $dummy->reportFilterRules())->fails());
        $this->assertFalse(Validator::make([
            'preset' => 'custom', 'from' => '2026-10-03', 'to' => '2026-10-03',
        ], $dummy->reportFilterRules())->fails());
    }

    public function test_caches_tool_results_deterministic_key(): void
    {
        $dummy = new class($this->fixture->owner)
        {
            use CachesToolResults;

            public function __construct(public User $user) {}
        };

        $args1 = ['preset' => 'today', 'compare' => 'previous'];
        $args2 = ['compare' => 'previous', 'preset' => 'today']; // reverse order

        $key1 = $dummy->toolCacheKey('test_tool', $args1);
        $key2 = $dummy->toolCacheKey('test_tool', $args2);

        // Key is identical regardless of argument order
        $this->assertSame($key1, $key2);

        // Contains tool name
        $this->assertStringContainsString('test_tool', $key1);

        // Version bump changes key
        $orgId = $this->fixture->organization->id;
        Cache::forever("metrics:version:{$orgId}", 5);

        $key3 = $dummy->toolCacheKey('test_tool', $args1);
        $this->assertNotSame($key1, $key3);
        $this->assertStringEndsWith(':5', $key3);
    }

    public function test_formats_tool_result_number_formatting(): void
    {
        $dummy = new class($this->fixture->owner)
        {
            use FormatsToolResult;

            public function __construct(public User $user) {}
        };

        // Monetary / count: 2 decimal places
        $this->assertSame(1234.57, $dummy->formatMetricNumber('K06', 1234.5678));
        $this->assertSame(100.0, $dummy->formatMetricNumber('K01', 100.0));

        // Ratios: 4 decimal places (0-1)
        $this->assertSame(0.2456, $dummy->formatMetricNumber('K10', 0.24561));
        $this->assertSame(0.0521, $dummy->formatMetricNumber('K16', 0.05214));
        $this->assertNull($dummy->formatMetricNumber('K10', null));
    }

    public function test_extracts_kpi_values_all_k_codes(): void
    {
        $dummy = new class
        {
            use ExtractsKpiValues;
        };

        $row = (object) [
            'orders_count' => 10,
            'gross_sales' => 1000.0,
            'discounts' => 100.0,
            'net_sales' => 900.0,
            'returns_amount' => 50.0,
            'net_sales_after_returns' => 850.0,
            'cogs' => 300.0,
            'returned_cogs_recovered' => 0.0,
            'gross_profit' => 550.0,
            'expenses_total' => 200.0,
            'labor_cost' => 100.0,
            'waste_cost' => 25.0,
            'inventory_variance_cost' => 15.0,
            'net_profit' => 310.0,
            'purchases_total' => 400.0,
            'purchase_returns_total' => 50.0,
            'cash_deficit_total' => 10.0,
            'cancelled_count' => 2,
        ];

        $kpis = KpiValues::fromRow($row);

        $this->assertEquals(10.0, $dummy->extractKpi('K01', $kpis));
        $this->assertEquals(1000.0, $dummy->extractKpi('K02', $kpis));
        $this->assertEquals(100.0, $dummy->extractKpi('K03', $kpis));
        $this->assertEquals(900.0, $dummy->extractKpi('K04', $kpis));
        $this->assertEquals(50.0, $dummy->extractKpi('K05', $kpis));
        $this->assertEquals(850.0, $dummy->extractKpi('K06', $kpis));
        $this->assertEquals(90.0, $dummy->extractKpi('K07', $kpis)); // 900 / 10
        $this->assertEquals(300.0, $dummy->extractKpi('K08', $kpis));
        $this->assertEquals(550.0, $dummy->extractKpi('K09', $kpis));
        $this->assertEquals(550.0 / 850.0, $dummy->extractKpi('K10', $kpis));
        $this->assertEquals(200.0, $dummy->extractKpi('K11', $kpis));
        $this->assertEquals(100.0, $dummy->extractKpi('K11a', $kpis));
        $this->assertEquals(25.0, $dummy->extractKpi('K12', $kpis));
        $this->assertEquals(15.0, $dummy->extractKpi('K13', $kpis));
        $this->assertEquals(310.0, $dummy->extractKpi('K14', $kpis));
        $this->assertEquals(310.0 / 850.0, $dummy->extractKpi('K15', $kpis));
        $this->assertEquals(300.0 / 850.0, $dummy->extractKpi('K16', $kpis));
        $this->assertEquals(25.0 / 850.0, $dummy->extractKpi('K17', $kpis));
        $this->assertEquals(350.0, $dummy->extractKpi('K20', $kpis)); // 400 - 50
        $this->assertEquals(2.0 / 12.0, $dummy->extractKpi('K21', $kpis)); // 2 / (10 + 2)
        $this->assertEquals(50.0 / 900.0, $dummy->extractKpi('K22', $kpis)); // 50 / 900
        $this->assertEquals(10.0, $dummy->extractKpi('K25', $kpis));

        // Aliases resolution
        $resolved = $dummy->resolveKpiCodes(['orders', 'sales', 'profit', 'unknown_code']);
        $this->assertContains('K01', $resolved);
        $this->assertContains('K06', $resolved);
        $this->assertContains('K14', $resolved);
    }
}
