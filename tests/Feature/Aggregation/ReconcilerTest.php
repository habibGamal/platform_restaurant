<?php

namespace Tests\Feature\Aggregation;

use App\Domain\Metrics\Aggregation\Reconciler;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchCommand;
use App\Models\Organization;
use App\Models\ReconciliationIssue;
use App\Models\Restaurant;
use App\Models\Warehouse\WhDaySummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcilerTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Restaurant $restaurant;

    protected Branch $branch;

    protected Reconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Org Test',
            'slug' => 'org-test',
            'plan' => 'pro',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->restaurant = Restaurant::create([
            'organization_id' => $this->organization->id,
            'name' => 'Restaurant Test',
        ]);

        $this->branch = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Branch Test',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);

        $this->reconciler = app(Reconciler::class);
    }

    public function test_match_returns_true_and_creates_no_issues(): void
    {
        $date = '2026-10-05';

        WhDaySummary::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'summary-match',
            'source_version' => 1,
            'business_date' => $date,
            'orders_count' => 5,
            'completed_count' => 5,
            'net_total' => 1000.00,
            'expenses_total' => 200.00,
            'waste_total' => 50.00,
        ]);

        $result = $this->reconciler->reconcile(
            $this->branch->id,
            $date,
            $this->organization->id,
            5,
            1000.00,
            200.00,
            50.00
        );

        $this->assertTrue($result);
        $this->assertDatabaseEmpty('reconciliation_issues');
        $this->assertDatabaseEmpty('branch_commands');
    }

    public function test_mismatch_creates_issue_and_resync_command(): void
    {
        $date = '2026-10-05';

        WhDaySummary::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'summary-mismatch',
            'source_version' => 1,
            'business_date' => $date,
            'completed_count' => 5,
            'net_total' => 1200.00, // POS says 1200
            'expenses_total' => 200.00,
            'waste_total' => 50.00,
        ]);

        $result = $this->reconciler->reconcile(
            $this->branch->id,
            $date,
            $this->organization->id,
            5,
            1000.00, // Platform says 1000
            200.00,
            50.00
        );

        $this->assertFalse($result);

        $issue = ReconciliationIssue::where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->where('field', 'net_total')
            ->first();

        $this->assertNotNull($issue);
        $this->assertEquals('1200.00', $issue->pos_value);
        $this->assertEquals('1000.00', $issue->platform_value);
        $this->assertEquals('resync_requested', $issue->status);
        $this->assertEquals(1, $issue->attempts);

        $command = BranchCommand::where('branch_id', $this->branch->id)->first();
        $this->assertNotNull($command);
        $this->assertEquals('resync', $command->type);
        $this->assertEquals(['from' => $date, 'to' => $date], $command->payload);
        $this->assertNull($command->delivered_at);
    }

    public function test_three_attempts_escalates_to_failed(): void
    {
        $date = '2026-10-05';

        WhDaySummary::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'summary-fail',
            'source_version' => 1,
            'business_date' => $date,
            'completed_count' => 5,
            'net_total' => 1200.00,
            'expenses_total' => 200.00,
            'waste_total' => 50.00,
        ]);

        // Attempt 1
        $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);
        $issue = ReconciliationIssue::first();
        $this->assertNotNull($issue);
        $this->assertEquals(1, $issue->attempts);
        $this->assertEquals('resync_requested', $issue->status);
        $this->assertEquals(1, BranchCommand::count());

        // Mark first command delivered
        BranchCommand::first()?->update(['delivered_at' => now()]);

        // Attempt 2
        $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);
        $issue->refresh();
        $this->assertEquals(2, $issue->attempts);
        $this->assertEquals('resync_requested', $issue->status);
        $this->assertEquals(2, BranchCommand::count());

        // Mark second command delivered
        BranchCommand::whereNull('delivered_at')->update(['delivered_at' => now()]);

        // Attempt 3
        $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);
        $issue->refresh();
        $this->assertEquals(3, $issue->attempts);
        $this->assertEquals('resync_requested', $issue->status);
        $this->assertEquals(3, BranchCommand::count());

        // Mark third command delivered
        BranchCommand::whereNull('delivered_at')->update(['delivered_at' => now()]);

        // Next run with attempts >= 3 -> status becomes failed and NO new command created
        $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);
        $issue->refresh();
        $this->assertEquals(3, $issue->attempts);
        $this->assertEquals('failed', $issue->status);
        $this->assertEquals(3, BranchCommand::count()); // Still 3 commands!
    }

    public function test_later_match_resolves_open_issue(): void
    {
        $date = '2026-10-05';

        $summary = WhDaySummary::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'summary-resolve',
            'source_version' => 1,
            'business_date' => $date,
            'completed_count' => 5,
            'net_total' => 1200.00,
            'expenses_total' => 200.00,
            'waste_total' => 50.00,
        ]);

        // Initial mismatch
        $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);
        $this->assertDatabaseHas('reconciliation_issues', [
            'branch_id' => $this->branch->id,
            'business_date' => $date,
            'field' => 'net_total',
            'status' => 'resync_requested',
        ]);

        // Correct the day summary
        $summary->update(['net_total' => 1000.00]);

        // Reconcile again
        $result = $this->reconciler->reconcile($this->branch->id, $date, $this->organization->id, 5, 1000.00, 200.00, 50.00);

        $this->assertTrue($result);
        $this->assertDatabaseHas('reconciliation_issues', [
            'branch_id' => $this->branch->id,
            'business_date' => $date,
            'field' => 'net_total',
            'status' => 'resolved',
        ]);
    }

    public function test_no_day_summary_returns_false_and_creates_no_issues(): void
    {
        $result = $this->reconciler->reconcile(
            $this->branch->id,
            '2026-10-05',
            $this->organization->id,
            5,
            1000.00,
            200.00,
            50.00
        );

        $this->assertFalse($result);
        $this->assertDatabaseEmpty('reconciliation_issues');
        $this->assertDatabaseEmpty('branch_commands');
    }
}
