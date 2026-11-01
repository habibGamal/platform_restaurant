<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Tools;

use App\Ai\Tools\Context\FindBranches;
use App\Ai\Tools\Context\GetWorkspaceContext;
use App\Ai\Tools\Context\ResolvePeriod;
use App\Enums\BranchStatus;
use App\Models\Branch;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class ContextToolsTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    public function test_get_workspace_context_returns_expected_metadata(): void
    {
        $tool = new GetWorkspaceContext($this->fixture->owner);

        $rawResult = $tool->handle(new Request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('error', $result);

        $this->assertNotEmpty($result['today']);
        $this->assertSame('Africa/Cairo', $result['timezone']);
        $this->assertSame('EGP', $result['currency']);

        $this->assertContains('org_owner', $result['roles']);
        $this->assertContains('ai.view', $result['permissions']);

        // Accessible branches include Branch A and Branch B
        $branchNames = array_column($result['accessible_branches'], 'name');
        $this->assertContains('Branch A', $branchNames);
        $this->assertContains('Branch B', $branchNames);

        // Branch status and opened_on exist
        $branchA = null;
        foreach ($result['accessible_branches'] as $branch) {
            if (is_array($branch) && ($branch['name'] ?? null) === 'Branch A') {
                $branchA = $branch;
                break;
            }
        }
        $this->assertNotNull($branchA);
        $this->assertSame('active', $branchA['status']);
        $this->assertSame('2026-09-01', $branchA['opened_on']);

        // Organization info
        $this->assertSame('pro', $result['organization']['plan']);
        $this->assertTrue($result['organization']['ai_enabled']);

        // Data freshness summary
        $this->assertNotNull($result['data_freshness']);
        $this->assertEquals(2, $result['data_freshness']['total_branches']);
    }

    public function test_resolve_period_returns_exact_dates(): void
    {
        $tool = new ResolvePeriod($this->fixture->owner);

        $request = new Request([
            'preset' => 'custom',
            'from' => '2026-10-01',
            'to' => '2026-10-07',
            'compare' => 'previous',
        ]);

        $rawResult = $tool->handle($request);
        $result = json_decode($rawResult, true);

        $this->assertIsArray($result);
        $this->assertSame('custom', $result['period']['preset']);
        $this->assertSame('2026-10-01', $result['period']['from']);
        $this->assertSame('2026-10-07', $result['period']['to']);
        $this->assertSame(7, $result['period']['days']);

        $this->assertNotNull($result['comparison']);
        $this->assertSame('previous', $result['comparison']['mode']);
        $this->assertSame('2026-09-24', $result['comparison']['from']);
        $this->assertSame('2026-09-30', $result['comparison']['to']);
        $this->assertSame(7, $result['comparison']['days']);
    }

    public function test_find_branches_arabic_normalization_and_scoping(): void
    {
        // Create Arabic-named branches
        $branchMaadi = Branch::create([
            'organization_id' => $this->fixture->organization->id,
            'restaurant_id' => $this->fixture->restaurant->id,
            'name' => 'فرع المعادي',
            'timezone' => 'Africa/Cairo',
            'status' => BranchStatus::ACTIVE,
        ]);

        $branchGiza = Branch::create([
            'organization_id' => $this->fixture->organization->id,
            'restaurant_id' => $this->fixture->restaurant->id,
            'name' => 'فرع الجيزة و الهرم',
            'timezone' => 'Africa/Cairo',
            'status' => BranchStatus::ACTIVE,
        ]);

        $tool = new FindBranches($this->fixture->owner);

        // 1. Alef variants (أ / إ / آ -> ا)
        $raw1 = $tool->handle(new Request(['query' => 'ألمعادي']));
        $res1 = json_decode($raw1, true);
        $this->assertEquals(1, $res1['count']);
        $this->assertSame($branchMaadi->id, $res1['matches'][0]['id']);

        // 2. Taa marbuta (ة -> ه)
        $raw2 = $tool->handle(new Request(['query' => 'الجيزه']));
        $res2 = json_decode($raw2, true);
        $this->assertEquals(1, $res2['count']);
        $this->assertSame($branchGiza->id, $res2['matches'][0]['id']);

        // 3. Tashkeel stripping
        $raw3 = $tool->handle(new Request(['query' => 'فَرْعُ المَعَادِي']));
        $res3 = json_decode($raw3, true);
        $this->assertEquals(1, $res3['count']);
        $this->assertSame($branchMaadi->id, $res3['matches'][0]['id']);

        // 4. Access scoping: manager of branch A only can NOT find Maadi or Giza
        $managerTool = new FindBranches($this->fixture->managerA);
        $raw4 = $managerTool->handle(new Request(['query' => 'المعادي']));
        $res4 = json_decode($raw4, true);
        $this->assertEquals(0, $res4['count']);
    }
}
