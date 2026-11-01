<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Metrics\GetKpis;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class GetKpisTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_exact_numbers_for_get_kpis_match_fixture(): void
    {
        $tool = new GetKpis($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'kpis' => ['K01', 'K06', 'K08', 'K09', 'K14'],
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('EGP', $result['currency']);
        $this->assertSame('2026-10-03', $result['period']['from']);
        $this->assertSame('2026-10-16', $result['period']['to']);

        $totals = $result['metrics']['totals'];

        // Total orders: A (84) + B (26) = 110
        $this->assertEquals(110, $totals['K01']['value']);

        // Net sales after returns: A (12780.00) + B (4160.00) = 16940.00
        $this->assertEquals(16940.00, $totals['K06']['value']);

        // Net profit: A (5950.00) + B (1745.00) = 7695.00
        $this->assertEquals(7695.00, $totals['K14']['value']);
    }

    public function test_get_kpis_by_branch_breakdown_matches_fixture(): void
    {
        $tool = new GetKpis($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'kpis' => ['K01', 'K06', 'K14'],
            'by_branch' => true,
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $byBranch = $result['metrics']['by_branch'];

        $branchAId = $this->fixture->branchA->id;
        $branchBId = $this->fixture->branchB->id;

        // Branch A expected: Orders = 84, Net Sales = 12780.00, Net Profit = 5950.00
        $this->assertEquals(84, $byBranch[$branchAId]['K01']['value']);
        $this->assertEquals(12780.00, $byBranch[$branchAId]['K06']['value']);
        $this->assertEquals(5950.00, $byBranch[$branchAId]['K14']['value']);

        // Branch B expected: Orders = 26, Net Sales = 4160.00, Net Profit = 1745.00
        $this->assertEquals(26, $byBranch[$branchBId]['K01']['value']);
        $this->assertEquals(4160.00, $byBranch[$branchBId]['K06']['value']);
        $this->assertEquals(1745.00, $byBranch[$branchBId]['K14']['value']);
    }

    public function test_branch_manager_of_branch_a_cannot_get_branch_b(): void
    {
        $tool = new GetKpis($this->fixture->managerA);

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
        $this->assertArrayNotHasKey('metrics', $result);
    }

    public function test_anonymized_branch_names_when_user_cannot_see_other_branch_names(): void
    {
        // User with access to both branches but without 'analytics.view_other_branch_names'
        $user = User::create([
            'organization_id' => $this->fixture->organization->id,
            'name' => 'Restricted Viewer',
            'email' => 'restricted@example.com',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('branch_manager');
        $user->branches()->attach([$this->fixture->branchA->id, $this->fixture->branchB->id]);

        $tool = new GetKpis($user);
        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'by_branch' => true,
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $branches = $result['branches'];

        $this->assertContains('فرع 1', $branches);
        $this->assertContains('فرع 2', $branches);
        $this->assertNotContains('Branch A', $branches);
        $this->assertNotContains('Branch B', $branches);
    }

    public function test_channel_filter_note_added_when_channel_requested(): void
    {
        $tool = new GetKpis($this->fixture->owner);

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

    public function test_validation_error_returned_as_result_without_throwing(): void
    {
        $tool = new GetKpis($this->fixture->owner);

        $request = new Request([
            'preset' => 'invalid_preset_value',
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('validation_error', $result['error']);
        $this->assertArrayHasKey('errors', $result);
    }

    public function test_size_cap_and_truncation(): void
    {
        $tool = new GetKpis($this->fixture->owner);

        // Subclass or test enforcePayloadSize with large simulated structure
        $hugeWarnings = array_fill(0, 1000, str_repeat('تحذير بيانات غير مكتملة طويل جداً ', 10));
        $largeEnvelope = [
            'period' => ['from' => '2026-10-03', 'to' => '2026-10-16'],
            'comparison' => null,
            'branches' => [1 => 'Branch A'],
            'currency' => 'EGP',
            'metrics' => ['totals' => ['K01' => ['value' => 100]]],
            'completeness' => [
                'summary' => ['complete' => 1, 'total' => 1],
                'warnings' => $hugeWarnings,
            ],
            'definitions' => ['K01' => 'Orders'],
            'notes' => [],
        ];

        $json = $tool->enforcePayloadSize($largeEnvelope, targetBytes: 6144, maxBytes: 16384);
        $decoded = json_decode($json, true);

        $this->assertLessThanOrEqual(16384, strlen($json));
        $this->assertTrue($decoded['truncated'] ?? false);
    }

    public function test_cache_hit_and_version_bump_invalidation(): void
    {
        $tool = new GetKpis($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
            'kpis' => ['K01'],
        ]);

        // 1. Initial call: computes and caches (Orders = 110)
        $raw1 = $tool->handle($request);
        $res1 = json_decode($raw1, true);
        $this->assertEquals(110, $res1['metrics']['totals']['K01']['value']);

        // 2. Direct DB mutation: add 50 orders
        DB::table('agg_branch_daily')
            ->where('branch_id', $this->fixture->branchA->id)
            ->where('business_date', '2026-10-03')
            ->increment('orders_count', 50);

        // 3. Second call: cache hit, still returns cached 110
        $raw2 = $tool->handle($request);
        $res2 = json_decode($raw2, true);
        $this->assertEquals(110, $res2['metrics']['totals']['K01']['value']);

        // 4. Bump org cache version
        $orgId = $this->fixture->organization->id;
        Cache::forever("metrics:version:{$orgId}", 1);

        // 5. Third call: cache miss, fresh computation returns 160
        $raw3 = $tool->handle($request);
        $res3 = json_decode($raw3, true);
        $this->assertEquals(160, $res3['metrics']['totals']['K01']['value']);
    }
}
