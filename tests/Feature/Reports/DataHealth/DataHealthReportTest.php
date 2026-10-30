<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\DataHealth;

use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Period\ReportFilters;
use App\Domain\Metrics\Reports\DataHealthReport;
use App\Models\BranchCommand;
use App\Models\IngestRejection;
use App\Models\ReconciliationIssue;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class DataHealthReportTest extends TestCase
{
    use RefreshDatabase;

    private TwoBranchesTwoWeeks $fixture;

    private CarbonImmutable $frozenNow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->frozenNow = CarbonImmutable::parse('2026-10-17 12:00:00', 'Africa/Cairo');
        CarbonImmutable::setTestNow($this->frozenNow);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        $this->fixture = TwoBranchesTwoWeeks::create();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Acceptance Criteria:
     * - Branch with heartbeat 30 min ago -> "delayed"
     * - Branch with heartbeat 2 h ago -> "offline"
     * - Branch with heartbeat 5 min ago and pending <= 500 -> "online"
     * - Branch with heartbeat 5 min ago but pending > 500 -> "delayed"
     */
    public function test_branch_status_rules_acceptance_criteria(): void
    {
        $now = $this->frozenNow;

        // 1. Heartbeat 30 min ago -> delayed
        $status30Min = DataHealthReport::computeBranchStatus(
            $now->subMinutes(30),
            pendingCount: 0,
            now: $now
        );
        $this->assertSame('delayed', $status30Min);

        // 2. Heartbeat 2 h ago (120 min) -> offline
        $status2Hours = DataHealthReport::computeBranchStatus(
            $now->subHours(2),
            pendingCount: 0,
            now: $now
        );
        $this->assertSame('offline', $status2Hours);

        // 3. Heartbeat 5 min ago, normal pending -> online
        $status5Min = DataHealthReport::computeBranchStatus(
            $now->subMinutes(5),
            pendingCount: 100,
            now: $now
        );
        $this->assertSame('online', $status5Min);

        // 4. Heartbeat 5 min ago, but pending > 500 -> delayed
        $statusHighPending = DataHealthReport::computeBranchStatus(
            $now->subMinutes(5),
            pendingCount: 501,
            now: $now
        );
        $this->assertSame('delayed', $statusHighPending);

        // 5. No heartbeat -> offline
        $statusNull = DataHealthReport::computeBranchStatus(
            null,
            pendingCount: 0,
            now: $now
        );
        $this->assertSame('offline', $statusNull);
    }

    /**
     * Acceptance Criteria:
     * - Fixture day where B is missing appears red ("missing") in the calendar.
     */
    public function test_fixture_day_where_b_is_missing_appears_red_in_calendar(): void
    {
        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = DataHealthReport::for($query);

        $calendarPeriod = new Period(
            CarbonImmutable::parse('2026-10-03', 'Africa/Cairo'),
            CarbonImmutable::parse('2026-10-16', 'Africa/Cairo')
        );

        $calendar = $report->completenessCalendar($calendarPeriod);

        $branchBRow = null;
        foreach ($calendar['branches'] as $branch) {
            if ($branch->branchId === $this->fixture->branchB->id) {
                $branchBRow = $branch;
                break;
            }
        }

        $this->assertNotNull($branchBRow, 'Branch B must be present in calendar for owner');

        // On 2026-10-10, Branch B is completely missing from warehouse and agg tables
        $this->assertArrayHasKey('2026-10-10', $branchBRow->days);
        $this->assertSame('missing', $branchBRow->days['2026-10-10']->status);

        // On 2026-10-14, Branch B has deliberate mismatch in summary (amber "closed")
        $this->assertArrayHasKey('2026-10-14', $branchBRow->days);
        $this->assertSame('closed', $branchBRow->days['2026-10-14']->status);

        // On 2026-10-03, Branch B is complete and reconciled
        $this->assertSame('complete', $branchBRow->days['2026-10-03']->status);

        // For Branch A, all 14 days must be complete
        $branchARow = null;
        foreach ($calendar['branches'] as $branch) {
            if ($branch->branchId === $this->fixture->branchA->id) {
                $branchARow = $branch;
                break;
            }
        }

        $this->assertNotNull($branchARow);
        $this->assertSame(14, $branchARow->completeDays);
        $this->assertSame(14, $branchARow->totalDays);

        // Overall coverage: 26 complete of 28 total (14 + 12 complete)
        $this->assertSame(26, $calendar['coverage']['complete']);
        $this->assertSame(28, $calendar['coverage']['total']);
    }

    /**
     * Acceptance Criteria:
     * - "Resync" action creates a pending command returned in B's next heartbeat response.
     */
    public function test_resync_action_creates_pending_command_for_branch(): void
    {
        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = DataHealthReport::for($query);

        // Find the reconciliation issue on 2026-10-14 for Branch B
        $issue = ReconciliationIssue::where('branch_id', $this->fixture->branchB->id)
            ->where('business_date', '2026-10-14')
            ->first();

        $this->assertNotNull($issue, 'Reconciliation issue for Branch B on 2026-10-14 must exist');
        $initialAttempts = $issue->attempts;

        // Trigger resync action as owner (has branches.manage permission)
        $command = $report->requestResync($issue->id, $this->fixture->owner);

        $this->assertInstanceOf(BranchCommand::class, $command);
        $this->assertSame($this->fixture->branchB->id, $command->branch_id);
        $this->assertSame('resync', $command->type);
        $this->assertNull($command->delivered_at, 'Command must be pending (delivered_at IS NULL)');

        /** @var array<string, mixed> $payload */
        $payload = $command->payload;
        $this->assertSame('2026-10-14', $payload['from']);
        $this->assertSame('2026-10-14', $payload['to']);

        // Check issue was updated
        $issue->refresh();
        $this->assertSame('resync_requested', $issue->status);
        $this->assertSame($initialAttempts + 1, $issue->attempts);

        // Simulate heartbeat retrieval query (pending commands where delivered_at is null)
        $pendingHeartbeatCommands = BranchCommand::where('branch_id', $this->fixture->branchB->id)
            ->whereNull('delivered_at')
            ->get();

        $this->assertTrue($pendingHeartbeatCommands->contains('id', $command->id));
    }

    public function test_resync_action_denied_for_user_without_permission(): void
    {
        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $report = DataHealthReport::for($query);

        $issue = ReconciliationIssue::first();
        $this->assertNotNull($issue);

        $this->expectException(AuthorizationException::class);
        $report->requestResync($issue->id, $this->fixture->managerA);
    }

    public function test_branch_status_table_numbers_and_version_outdated_badge(): void
    {
        // Set heartbeat and versions on branches
        $this->fixture->branchA->update([
            'last_heartbeat_at' => $this->frozenNow->subMinutes(5),
            'last_batch_at' => $this->frozenNow->subMinutes(2),
            'pos_version' => '2.5.0',
            'schema_version' => 1,
            'pending_count' => 12,
            'failed_count' => 0,
        ]);

        $this->fixture->branchB->update([
            'last_heartbeat_at' => $this->frozenNow->subMinutes(35),
            'last_batch_at' => $this->frozenNow->subMinutes(40),
            'pos_version' => '2.1.0', // older than branch A's 2.5.0
            'schema_version' => 1,
            'pending_count' => 120,
            'failed_count' => 3,
        ]);

        // Insert a rejection for branch B
        IngestRejection::create([
            'organization_id' => $this->fixture->organization->id,
            'branch_id' => $this->fixture->branchB->id,
            'doc_type' => 'order',
            'doc_uuid' => 'order-rej-1',
            'reason' => 'invalid tax calculation in envelope',
            'pos_version' => '2.1.0',
            'created_at' => $this->frozenNow->subDays(2),
        ]);

        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = DataHealthReport::for($query);
        $rows = $report->branchStatusTable();

        $this->assertCount(2, $rows);

        $rowA = $rows[0]->branchId === $this->fixture->branchA->id ? $rows[0] : $rows[1];
        $rowB = $rows[0]->branchId === $this->fixture->branchB->id ? $rows[0] : $rows[1];

        // Branch A assertions
        $this->assertSame('Branch A', $rowA->branchName);
        $this->assertSame('online', $rowA->status);
        $this->assertSame('2.5.0', $rowA->posVersion);
        $this->assertFalse($rowA->isOutdated);
        $this->assertSame(12, $rowA->pendingCount);
        $this->assertSame(0, $rowA->failedCount);
        $this->assertSame(0, $rowA->rejectedDocs7Days);

        // Branch B assertions
        $this->assertSame('Branch B', $rowB->branchName);
        $this->assertSame('delayed', $rowB->status);
        $this->assertSame('2.1.0', $rowB->posVersion);
        $this->assertTrue($rowB->isOutdated, 'Branch B version 2.1.0 must be flagged outdated compared to 2.5.0');
        $this->assertSame(120, $rowB->pendingCount);
        $this->assertSame(3, $rowB->failedCount);
        $this->assertSame(1, $rowB->rejectedDocs7Days);
    }

    public function test_rejections_log_groups_and_returns_expected_fields(): void
    {
        $bId = $this->fixture->branchB->id;
        $orgId = $this->fixture->organization->id;

        IngestRejection::create([
            'organization_id' => $orgId,
            'branch_id' => $bId,
            'doc_type' => 'order',
            'doc_uuid' => 'order-rej-dup',
            'reason' => 'Duplicate UUID',
            'pos_version' => '2.1.0',
            'created_at' => $this->frozenNow->subDays(3),
        ]);

        IngestRejection::create([
            'organization_id' => $orgId,
            'branch_id' => $bId,
            'doc_type' => 'order',
            'doc_uuid' => 'order-rej-dup',
            'reason' => 'Duplicate UUID',
            'pos_version' => '2.1.0',
            'created_at' => $this->frozenNow->subDays(1),
        ]);

        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = DataHealthReport::for($query);
        $log = $report->rejectionsLog();

        $this->assertNotEmpty($log);
        $entry = $log[0];
        $this->assertSame($bId, $entry->branchId);
        $this->assertSame('order', $entry->docType);
        $this->assertSame('order-rej-dup', $entry->docUuid);
        $this->assertSame('Duplicate UUID', $entry->reason);
        $this->assertSame(2, $entry->count);
        $this->assertNotNull($entry->firstSeenAt);
        $this->assertNotNull($entry->lastSeenAt);
    }

    public function test_pos_vs_platform_profit_check_flags_drift_greater_than_one_percent(): void
    {
        $filters = ReportFilters::fromArray([
            'preset' => 'custom',
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->owner, $filters);
        $report = DataHealthReport::for($query);

        // Initially no profit drift in fixture (no profit_pos sent)
        $this->assertEmpty($report->profitDriftCheck());

        // Update an order on 2026-10-03 for Branch A to introduce profit drift:
        // Platform gross profit for Branch A on 2026-10-03 is 560.00 (from EXPECTED)
        // Set profit_pos on order to 400.00 (diff > 1%)
        DB::table('wh_orders')
            ->where('branch_id', $this->fixture->branchA->id)
            ->where('business_date', '2026-10-03')
            ->where('status', 'completed')
            ->limit(1)
            ->update(['profit_pos' => 400.00]);

        $drifts = $report->profitDriftCheck();
        $this->assertCount(1, $drifts);

        $drift = $drifts[0];
        $this->assertSame($this->fixture->branchA->id, $drift->branchId);
        $this->assertSame('2026-10-03', $drift->date);
        $this->assertEquals(400.00, $drift->posProfit);
        $this->assertEquals(560.00, $drift->platformProfit);
        $this->assertEquals(160.00, $drift->diff);
        $this->assertGreaterThan(1.0, $drift->diffPercent);
    }

    public function test_manager_a_scoping_never_includes_branch_b(): void
    {
        $filters = ReportFilters::fromArray([
            'from' => '2026-10-03',
            'to' => '2026-10-16',
        ], $this->frozenNow);

        $query = MetricsQuery::for($this->fixture->managerA, $filters);
        $report = DataHealthReport::for($query);

        // Branch status table only contains Branch A
        $statusRows = $report->branchStatusTable();
        $this->assertCount(1, $statusRows);
        $this->assertSame($this->fixture->branchA->id, $statusRows[0]->branchId);

        // Calendar only contains Branch A
        $calendar = $report->completenessCalendar();
        $this->assertCount(1, $calendar['branches']);
        $this->assertSame($this->fixture->branchA->id, $calendar['branches'][0]->branchId);

        // Issues: Branch B's issue is hidden from Manager A
        $issues = $report->reconciliationIssues();
        $this->assertEmpty($issues);
    }
}
