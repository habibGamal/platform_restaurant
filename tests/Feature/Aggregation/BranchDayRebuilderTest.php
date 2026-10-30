<?php

namespace Tests\Feature\Aggregation;

use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Enums\BranchStatus;
use App\Events\BranchDayUpdated;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\Warehouse\DimExpenseType;
use App\Models\Warehouse\WhExpense;
use App\Models\Warehouse\WhOrder;
use App\Models\Warehouse\WhOrderItem;
use App\Models\Warehouse\WhOrderReturn;
use App\Models\Warehouse\WhPayment;
use App\Models\Warehouse\WhShift;
use App\Models\Warehouse\WhWaste;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BranchDayRebuilderTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected Restaurant $restaurant;

    protected Branch $branch;

    protected BranchDayAggregator $rebuilder;

    /** @return array<string, array{?string, ?string, ?int}> */
    public static function shiftDurations(): array
    {
        return [
            'normal shift' => ['2026-10-05 09:00:00', '2026-10-05 17:00:00', 480],
            'crosses midnight' => ['2026-10-05 22:00:00', '2026-10-06 06:00:00', 480],
            'legacy same-day overnight' => ['2026-10-05 22:00:00', '2026-10-05 06:00:00', 480],
            'multiple days' => ['2026-10-05 09:00:00', '2026-10-07 10:00:00', 2940],
            'same instant' => ['2026-10-05 09:00:00', '2026-10-05 09:00:00', 0],
            'open shift' => ['2026-10-05 09:00:00', null, null],
            'missing start' => [null, '2026-10-05 17:00:00', null],
            'previous-day end' => ['2026-10-05 09:00:00', '2026-10-04 17:00:00', null],
            'imported start months after end' => ['2026-10-30 13:42:10', '2026-08-02 05:34:37', null],
        ];
    }

    #[DataProvider('shiftDurations')]
    public function test_shift_duration_does_not_prevent_rebuilding_sales(?string $start, ?string $end, ?int $expectedMinutes): void
    {
        $date = '2026-10-05';
        WhShift::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'duration-shift',
            'source_version' => 1,
            'business_date' => $date,
            'start_at' => $start,
            'end_at' => $end,
            'closed' => $end !== null,
        ]);
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'duration-order',
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => 'duration-shift',
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => '100.00',
            'total' => '100.00',
        ]);

        $this->rebuilder->recompute($this->branch->id, $date);
        $this->rebuilder->recompute($this->branch->id, $date);

        $this->assertDatabaseHas('agg_shift', [
            'branch_id' => $this->branch->id,
            'shift_uuid' => 'duration-shift',
            'duration_minutes' => $expectedMinutes,
            'start_at' => $start,
            'end_at' => $end,
            'orders_count' => 1,
            'net_sales' => '100.00',
        ]);
        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => $date,
            'net_sales' => '100.00',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org',
            'plan' => 'pro',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
        ]);

        $this->restaurant = Restaurant::create([
            'organization_id' => $this->organization->id,
            'name' => 'Test Restaurant',
        ]);

        $this->branch = Branch::create([
            'organization_id' => $this->organization->id,
            'restaurant_id' => $this->restaurant->id,
            'name' => 'Main Branch',
            'timezone' => 'Africa/Cairo',
            'opened_on' => '2026-09-01',
            'status' => BranchStatus::ACTIVE,
        ]);

        $this->rebuilder = app(BranchDayAggregator::class);
    }

    public function test_completed_vs_cancelled_orders_and_sales_breakdown(): void
    {
        $date = '2026-10-05';

        // 1 completed order
        $order1 = WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-completed-1',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'discount' => 10.00,
            'items_discount' => 5.00,
            'tax' => 14.00,
            'service' => 10.00,
            'total' => 109.00, // 100 - 10 - 5 + 14 + 10 = 109
            'cogs' => 40.00,
            'hour_of_day' => 14,
        ]);

        WhPayment::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'order_uuid' => 'ord-completed-1',
            'method' => 'cash',
            'amount' => 109.00,
        ]);

        // 1 cancelled order
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-cancelled-1',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'takeaway',
            'status' => 'cancelled',
            'sub_total' => 80.00,
            'total' => 80.00,
            'hour_of_day' => 15,
        ]);

        $this->rebuilder->recompute($this->branch->id, $date);

        $row = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals(1, $row->orders_count);
        $this->assertEquals(1, $row->cancelled_count);
        $this->assertEquals('80.00', $row->cancelled_value);
        $this->assertEquals('100.00', $row->gross_sales);
        $this->assertEquals('15.00', $row->discounts);
        $this->assertEquals('14.00', $row->tax);
        $this->assertEquals('10.00', $row->service);
        $this->assertEquals('95.00', $row->net_sales); // 109 - 14 = 95
        $this->assertEquals('95.00', $row->net_sales_after_returns);
        $this->assertEquals('40.00', $row->cogs);
        $this->assertEquals('55.00', $row->gross_profit); // 95 - 40 = 55
        $this->assertEquals('55.00', $row->net_profit);

        $salesByType = json_decode((string) $row->sales_by_type, true);
        $this->assertArrayHasKey('dine_in', $salesByType);
        $this->assertEquals(1, $salesByType['dine_in']['orders']);
        $this->assertEquals('95.00', $salesByType['dine_in']['net_sales']);
        $this->assertArrayNotHasKey('takeaway', $salesByType);

        $salesByMethod = json_decode((string) $row->sales_by_method, true);
        $this->assertArrayHasKey('cash', $salesByMethod);
        $this->assertEquals('109.00', $salesByMethod['cash']);
    }

    public function test_returns_on_different_date_with_reverse_stock_true_and_false(): void
    {
        $day1 = '2026-10-01';
        $day2 = '2026-10-02';

        // Day 1: completed order
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-day1',
            'source_version' => 1,
            'business_date' => $day1,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 200.00,
            'total' => 200.00,
            'cogs' => 80.00,
        ]);

        // Day 2: completed order
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-day2',
            'source_version' => 1,
            'business_date' => $day2,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'total' => 100.00,
            'cogs' => 30.00,
        ]);

        // Day 2: Return 1 with reverse_stock = true
        WhOrderReturn::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ret-1',
            'source_version' => 1,
            'business_date' => $day2,
            'order_uuid' => 'ord-day1',
            'total_refund' => 50.00,
            'reverse_stock' => true,
            'returned_cogs' => 20.00,
        ]);

        // Day 2: Return 2 with reverse_stock = false
        WhOrderReturn::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ret-2',
            'source_version' => 1,
            'business_date' => $day2,
            'order_uuid' => 'ord-day1',
            'total_refund' => 30.00,
            'reverse_stock' => false,
            'returned_cogs' => 15.00,
        ]);

        $this->rebuilder->recompute($this->branch->id, $day1);
        $this->rebuilder->recompute($this->branch->id, $day2);

        $row1 = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $day1)
            ->first();

        $this->assertEquals('200.00', $row1->net_sales);
        $this->assertEquals('0.00', $row1->returns_amount);
        $this->assertEquals('200.00', $row1->net_sales_after_returns);
        $this->assertEquals('80.00', $row1->cogs);
        $this->assertEquals('0.00', $row1->returned_cogs_recovered);
        $this->assertEquals('120.00', $row1->gross_profit);

        $row2 = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $day2)
            ->first();

        $this->assertEquals('100.00', $row2->net_sales);
        $this->assertEquals('80.00', $row2->returns_amount);
        $this->assertEquals('20.00', $row2->net_sales_after_returns);
        $this->assertEquals('30.00', $row2->cogs);
        $this->assertEquals('20.00', $row2->returned_cogs_recovered); // only ret-1 recovered
        // Gross profit = net_sales_after_returns - (cogs - returned_cogs_recovered) = 20 - (30 - 20) = 10
        $this->assertEquals('10.00', $row2->gross_profit);
    }

    public function test_expense_categories_including_override_and_unknown_type(): void
    {
        $date = '2026-10-05';

        // 1 completed order for base sales
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-exp-test',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 500.00,
            'total' => 500.00,
            'cogs' => 100.00,
        ]);

        // Dim expense types
        DimExpenseType::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-type-override',
            'source_version' => 1,
            'name' => 'Bonus',
            'category' => 'other',
            'category_override' => 'labor', // override wins
        ]);

        DimExpenseType::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-type-rent',
            'source_version' => 1,
            'name' => 'Monthly Rent',
            'category' => 'rent',
        ]);

        DimExpenseType::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-type-other',
            'source_version' => 1,
            'name' => 'Office Supplies',
            'category' => 'office', // other
        ]);

        // Wh expenses
        WhExpense::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-1',
            'source_version' => 1,
            'business_date' => $date,
            'expense_type_uuid' => 'exp-type-override',
            'amount' => 100.00,
        ]);

        WhExpense::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-2',
            'source_version' => 1,
            'business_date' => $date,
            'expense_type_uuid' => 'exp-type-rent',
            'amount' => 200.00,
        ]);

        WhExpense::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-3',
            'source_version' => 1,
            'business_date' => $date,
            'expense_type_uuid' => 'exp-type-other',
            'amount' => 50.00,
        ]);

        WhExpense::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'exp-4',
            'source_version' => 1,
            'business_date' => $date,
            'expense_type_uuid' => 'non-existent-type',
            'amount' => 30.00,
        ]);

        $this->rebuilder->recompute($this->branch->id, $date);

        $row = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();

        $this->assertEquals('380.00', $row->expenses_total);
        $this->assertEquals('100.00', $row->labor_cost);
        $this->assertEquals('200.00', $row->rent_cost);
        $this->assertEquals('80.00', $row->other_expenses); // 50 + 30

        // Gross profit = 500 - 100 = 400. Net profit = 400 - 380 = 20.
        $this->assertEquals('20.00', $row->net_profit);

        // Check agg_expense_daily
        $expDaily = DB::table('agg_expense_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->get()
            ->keyBy('expense_type_uuid');

        $this->assertCount(4, $expDaily);
        $this->assertEquals('labor', $expDaily['exp-type-override']->category);
        $this->assertEquals('100.00', $expDaily['exp-type-override']->amount);
        $this->assertEquals('rent', $expDaily['exp-type-rent']->category);
        $this->assertEquals('200.00', $expDaily['exp-type-rent']->amount);
        $this->assertEquals('office', $expDaily['exp-type-other']->category);
        $this->assertEquals('50.00', $expDaily['exp-type-other']->amount);
        $this->assertEquals('other', $expDaily['non-existent-type']->category);
        $this->assertEquals('30.00', $expDaily['non-existent-type']->amount);
    }

    public function test_deleted_rows_are_ignored(): void
    {
        $date = '2026-10-05';

        // Active completed order
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-active',
            'source_version' => 1,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'total' => 100.00,
            'cogs' => 40.00,
        ]);

        // Soft-deleted completed order
        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-deleted',
            'source_version' => 2,
            'business_date' => $date,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 500.00,
            'total' => 500.00,
            'cogs' => 200.00,
            'deleted_at' => now(),
        ]);

        // Active waste
        WhWaste::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'waste-active',
            'source_version' => 1,
            'business_date' => $date,
            'total' => 20.00,
        ]);

        // Soft-deleted waste
        WhWaste::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'waste-deleted',
            'source_version' => 2,
            'business_date' => $date,
            'total' => 100.00,
            'deleted_at' => now(),
        ]);

        $this->rebuilder->recompute($this->branch->id, $date);

        $row = DB::table('agg_branch_daily')
            ->where('branch_id', $this->branch->id)
            ->where('business_date', $date)
            ->first();

        $this->assertEquals(1, $row->orders_count);
        $this->assertEquals('100.00', $row->net_sales);
        $this->assertEquals('20.00', $row->waste_cost);
    }

    public function test_moving_order_to_another_date_then_recomputing_both_dates(): void
    {
        $day1 = '2026-10-01';
        $day2 = '2026-10-02';

        $order = WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-moving',
            'source_version' => 1,
            'business_date' => $day1,
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 150.00,
            'total' => 150.00,
            'cogs' => 50.00,
        ]);

        $this->rebuilder->recompute($this->branch->id, $day1);
        $this->rebuilder->recompute($this->branch->id, $day2);

        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => $day1,
            'orders_count' => 1,
        ]);
        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => $day2,
        ]);

        // Move order to Day 2
        $order->update(['business_date' => $day2]);

        $this->rebuilder->recompute($this->branch->id, $day1);
        $this->rebuilder->recompute($this->branch->id, $day2);

        // Day 1 now has no data -> agg row should be gone!
        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => $day1,
        ]);
        // Day 2 has the order
        $this->assertDatabaseHas('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => $day2,
            'orders_count' => 1,
            'net_sales' => 150.00,
        ]);
    }

    public function test_idempotent_rerun(): void
    {
        $date = '2026-10-05';

        WhShift::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'shift-1',
            'source_version' => 1,
            'business_date' => $date,
            'closed' => true,
        ]);

        WhOrder::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'uuid' => 'ord-idem',
            'source_version' => 1,
            'business_date' => $date,
            'shift_uuid' => 'shift-1',
            'type' => 'dine_in',
            'status' => 'completed',
            'sub_total' => 100.00,
            'total' => 100.00,
            'cogs' => 40.00,
            'hour_of_day' => 12,
        ]);

        WhOrderItem::create([
            'organization_id' => $this->organization->id,
            'branch_id' => $this->branch->id,
            'order_uuid' => 'ord-idem',
            'uuid' => 'item-idem',
            'product_uuid' => 'prod-test',
            'product_ref' => 'REF-1',
            'quantity' => 1,
            'price' => 100.00,
            'unit_cost' => 40.00,
            'total' => 100.00,
        ]);

        // Run 1
        $this->rebuilder->recompute($this->branch->id, $date);

        $countBranchDaily1 = DB::table('agg_branch_daily')->where('branch_id', $this->branch->id)->count();
        $countProductDaily1 = DB::table('agg_product_daily')->where('branch_id', $this->branch->id)->count();
        $countShift1 = DB::table('agg_shift')->where('branch_id', $this->branch->id)->count();
        $countHourly1 = DB::table('agg_branch_hourly')->where('branch_id', $this->branch->id)->count();

        $this->assertEquals(1, $countBranchDaily1);
        $this->assertEquals(1, $countProductDaily1);
        $this->assertEquals(1, $countShift1);
        $this->assertEquals(1, $countHourly1);

        // Run 2
        $this->rebuilder->recompute($this->branch->id, $date);

        $this->assertEquals($countBranchDaily1, DB::table('agg_branch_daily')->where('branch_id', $this->branch->id)->count());
        $this->assertEquals($countProductDaily1, DB::table('agg_product_daily')->where('branch_id', $this->branch->id)->count());
        $this->assertEquals($countShift1, DB::table('agg_shift')->where('branch_id', $this->branch->id)->count());
        $this->assertEquals($countHourly1, DB::table('agg_branch_hourly')->where('branch_id', $this->branch->id)->count());
    }

    public function test_event_dispatched_even_when_no_warehouse_data(): void
    {
        Event::fake([BranchDayUpdated::class]);

        $this->rebuilder->recompute($this->branch->id, '2026-10-20');

        Event::assertDispatched(BranchDayUpdated::class, function (BranchDayUpdated $event) {
            return $event->organizationId === $this->organization->id
                && $event->branchId === $this->branch->id
                && $event->businessDate === '2026-10-20';
        });

        $this->assertDatabaseMissing('agg_branch_daily', [
            'branch_id' => $this->branch->id,
            'business_date' => '2026-10-20',
        ]);
    }
}
