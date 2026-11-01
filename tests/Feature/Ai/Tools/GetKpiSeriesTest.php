<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Metrics\GetKpiSeries;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class GetKpiSeriesTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_series_daily_buckets_match_fixture(): void
    {
        $tool = new GetKpiSeries($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'granularity' => 'day',
            'kpis' => ['K01', 'K06'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('day', $result['metrics']['granularity']);
        $this->assertFalse($result['metrics']['per_branch']);

        $series = $result['metrics']['series'];

        // 2026-10-03: Branch A (6 orders, 920.00 sales) + Branch B (2 orders, 320.00 sales) = 8 orders, 1240.00 sales
        $this->assertArrayHasKey('2026-10-03', $series);
        $this->assertEquals(8, $series['2026-10-03']['K01']);
        $this->assertEquals(1240.00, $series['2026-10-03']['K06']);
    }

    public function test_series_per_branch_breakdown(): void
    {
        $tool = new GetKpiSeries($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'granularity' => 'day',
            'per_branch' => true,
            'kpis' => ['K01', 'K06'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertTrue($result['metrics']['per_branch']);

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        $series = $result['metrics']['series'];
        $this->assertArrayHasKey($branchAId, $series);
        $this->assertArrayHasKey($branchBId, $series);

        // Branch A on 2026-10-03: 6 orders, 920.00
        $this->assertEquals(6, $series[$branchAId]['2026-10-03']['K01']);
        $this->assertEquals(920.00, $series[$branchAId]['2026-10-03']['K06']);

        // Branch B on 2026-10-03: 2 orders, 320.00
        $this->assertEquals(2, $series[$branchBId]['2026-10-03']['K01']);
        $this->assertEquals(320.00, $series[$branchBId]['2026-10-03']['K06']);
    }

    public function test_series_channel_note_added_when_requested(): void
    {
        $tool = new GetKpiSeries($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'channel' => 'pos',
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertContains('channel filter not supported here', $result['notes']);
    }

    public function test_manager_cannot_get_forbidden_branch_series(): void
    {
        $tool = new GetKpiSeries($this->fixture->managerA);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'branches' => [$this->fixture->branchB->id],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('forbidden_branch', $result['error']);
        $this->assertNotEmpty($result['message_ar']);
    }
}
