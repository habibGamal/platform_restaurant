<?php

namespace Tests\Feature\Aggregation;

use App\Models\BranchCommand;
use App\Models\ReconciliationIssue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\TwoBranchesTwoWeeks;
use Tests\TestCase;

class TwoBranchesTwoWeeksFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixture_matches_expected_numbers_and_produces_reconciliation_issue(): void
    {
        $fixture = TwoBranchesTwoWeeks::create();

        // Check Branch A expected numbers
        foreach (TwoBranchesTwoWeeks::EXPECTED['A'] as $date => $expected) {
            $row = DB::table('agg_branch_daily')
                ->where('branch_id', $fixture->branchA->id)
                ->where('business_date', $date)
                ->first();

            $this->assertNotNull($row, "Missing agg_branch_daily row for Branch A on {$date}");
            $this->assertEquals($expected['orders_count'], $row->orders_count, "Branch A {$date} orders_count mismatch");
            $this->assertEquals($expected['net_sales'], $row->net_sales, "Branch A {$date} net_sales mismatch");
            $this->assertEquals($expected['net_sales_after_returns'], $row->net_sales_after_returns, "Branch A {$date} net_sales_after_returns mismatch");
            $this->assertEquals($expected['cogs'], $row->cogs, "Branch A {$date} cogs mismatch");
            $this->assertEquals($expected['gross_profit'], $row->gross_profit, "Branch A {$date} gross_profit mismatch");
            $this->assertEquals($expected['expenses_total'], $row->expenses_total, "Branch A {$date} expenses_total mismatch");
            $this->assertEquals($expected['labor_cost'], $row->labor_cost, "Branch A {$date} labor_cost mismatch");
            $this->assertEquals($expected['waste_cost'], $row->waste_cost, "Branch A {$date} waste_cost mismatch");
            $this->assertEquals($expected['inventory_variance_cost'], $row->inventory_variance_cost, "Branch A {$date} inventory_variance_cost mismatch");
            $this->assertEquals($expected['net_profit'], $row->net_profit, "Branch A {$date} net_profit mismatch");
            $this->assertTrue((bool) $row->is_day_closed, "Branch A {$date} should be closed");
            $this->assertTrue((bool) $row->is_reconciled, "Branch A {$date} should be reconciled");
        }

        // Check Branch B expected numbers
        foreach (TwoBranchesTwoWeeks::EXPECTED['B'] as $date => $expected) {
            $row = DB::table('agg_branch_daily')
                ->where('branch_id', $fixture->branchB->id)
                ->where('business_date', $date)
                ->first();

            $this->assertNotNull($row, "Missing agg_branch_daily row for Branch B on {$date}");
            $this->assertEquals($expected['orders_count'], $row->orders_count, "Branch B {$date} orders_count mismatch");
            $this->assertEquals($expected['net_sales'], $row->net_sales, "Branch B {$date} net_sales mismatch");
            $this->assertEquals($expected['net_sales_after_returns'], $row->net_sales_after_returns, "Branch B {$date} net_sales_after_returns mismatch");
            $this->assertEquals($expected['cogs'], $row->cogs, "Branch B {$date} cogs mismatch");
            $this->assertEquals($expected['gross_profit'], $row->gross_profit, "Branch B {$date} gross_profit mismatch");
            $this->assertEquals($expected['expenses_total'], $row->expenses_total, "Branch B {$date} expenses_total mismatch");
            $this->assertEquals($expected['labor_cost'], $row->labor_cost, "Branch B {$date} labor_cost mismatch");
            $this->assertEquals($expected['waste_cost'], $row->waste_cost, "Branch B {$date} waste_cost mismatch");
            $this->assertEquals($expected['inventory_variance_cost'], $row->inventory_variance_cost, "Branch B {$date} inventory_variance_cost mismatch");
            $this->assertEquals($expected['net_profit'], $row->net_profit, "Branch B {$date} net_profit mismatch");
            $this->assertTrue((bool) $row->is_day_closed, "Branch B {$date} should be closed");

            if ($date === '2026-10-14') {
                $this->assertFalse((bool) $row->is_reconciled, "Branch B {$date} has deliberate mismatch, should not be reconciled");
            } else {
                $this->assertTrue((bool) $row->is_reconciled, "Branch B {$date} should be reconciled");
            }
        }

        // Branch B on 2026-10-10 has NO row at all
        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $fixture->branchB->id,
            'business_date' => '2026-10-10',
        ]);

        // Deliberate mismatch on B 2026-10-14 produced an issue
        $issue = ReconciliationIssue::where('branch_id', $fixture->branchB->id)
            ->where('business_date', '2026-10-14')
            ->where('field', 'net_total')
            ->first();

        $this->assertNotNull($issue, 'Reconciliation issue should exist for Branch B on 2026-10-14');
        $this->assertEquals('400.00', $issue->pos_value);
        $this->assertEquals('320.00', $issue->platform_value);
        $this->assertEquals('resync_requested', $issue->status);
        $this->assertEquals(1, $issue->attempts);

        // Assert resync branch command was created
        $command = BranchCommand::where('branch_id', $fixture->branchB->id)
            ->where('type', 'resync')
            ->first();

        $this->assertNotNull($command, 'BranchCommand for resync should exist');
        $this->assertEquals(['from' => '2026-10-14', 'to' => '2026-10-14'], $command->payload);
        $this->assertNull($command->delivered_at);
    }
}
