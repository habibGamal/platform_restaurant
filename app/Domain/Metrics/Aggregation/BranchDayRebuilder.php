<?php

namespace App\Domain\Metrics\Aggregation;

use App\Domain\Ingestion\Exceptions\FencedWorkerException;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Events\BranchDayUpdated;
use App\Support\Weekday;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BranchDayRebuilder implements BranchDayAggregator
{
    public function __construct(
        protected Reconciler $reconciler
    ) {}

    public function recompute(int $branchId, string $businessDate, ?int $fenceToken = null): void
    {
        $fenceToken ??= DirtyDateOutbox::claimRecomputeFence($branchId, $businessDate);

        DB::transaction(function () use ($branchId, $businessDate, $fenceToken) {

            // Keep competing recomputations serialized for the entire rebuild,
            // including workers whose cache lease expired during a long query.
            $fence = DB::table('ingest_recompute_fences')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->lockForUpdate()
                ->first();

            if ($fence !== null && $fenceToken < (int) $fence->committed_token) {
                throw new FencedWorkerException('Recompute worker has been superseded.');
            }

            // 1. Delete all agg_* rows for this (branch, date)
            DB::table('agg_branch_daily')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('agg_product_daily')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('agg_shift')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('agg_branch_hourly')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('agg_expense_daily')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            DB::table('agg_purchase_daily')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->delete();

            $branch = DB::table('branches')
                ->where('id', $branchId)
                ->first(['id', 'organization_id', 'restaurant_id']);

            if (! $branch) {
                return;
            }

            $organizationId = (int) $branch->organization_id;
            $restaurantId = (int) $branch->restaurant_id;

            // If the branch has no warehouse data at all for that date, leave no agg rows (still fire the event)
            if (! $this->hasWarehouseData($branchId, $businessDate)) {

                if (! DirtyDateOutbox::isFenceCurrent($branchId, $businessDate, $fenceToken)) {
                    throw new FencedWorkerException("Recompute worker fenced: fence token {$fenceToken} superseded.");
                }
                DirtyDateOutbox::commitRecomputeFence($branchId, $businessDate, $fenceToken);

                BranchDayUpdated::dispatch($organizationId, $branchId, $businessDate);

                return;
            }

            $weekday = Weekday::of(Carbon::parse($businessDate));

            // 2. Orders & Sales (completed vs cancelled)
            $orders = DB::table('wh_orders')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->get();

            $completedOrders = $orders->where('status', 'completed');
            $cancelledOrders = $orders->where('status', 'cancelled');

            $ordersCount = $completedOrders->count();
            $cancelledCount = $cancelledOrders->count();
            $cancelledValue = (float) $cancelledOrders->sum('total');

            $grossSales = (float) $completedOrders->sum('sub_total');
            $discounts = (float) $completedOrders->sum(fn ($o) => (float) $o->discount + (float) $o->items_discount);
            $tax = (float) $completedOrders->sum('tax');
            $service = (float) $completedOrders->sum('service');
            $netSales = (float) $completedOrders->sum(fn ($o) => (float) $o->total - (float) $o->tax);
            $cogs = (float) $completedOrders->sum('cogs');

            // 3. Returns of this business_date
            $returns = DB::table('wh_order_returns')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->get();

            $returnsAmount = (float) $returns->sum('total_refund');
            $returnedCogsRecovered = (float) $returns
                ->filter(fn ($r) => (bool) $r->reverse_stock)
                ->sum('returned_cogs');

            $netSalesAfterReturns = $netSales - $returnsAmount;
            $grossProfit = $netSalesAfterReturns - ($cogs - $returnedCogsRecovered);

            // 4. Expenses
            $expenses = DB::table('wh_expenses')
                ->leftJoin('dim_expense_types', function ($join) {
                    $join->on('wh_expenses.branch_id', '=', 'dim_expense_types.branch_id')
                        ->on('wh_expenses.expense_type_uuid', '=', 'dim_expense_types.uuid')
                        ->whereNull('dim_expense_types.deleted_at');
                })
                ->where('wh_expenses.branch_id', $branchId)
                ->where('wh_expenses.business_date', $businessDate)
                ->whereNull('wh_expenses.deleted_at')
                ->select([
                    'wh_expenses.uuid',
                    'wh_expenses.expense_type_uuid',
                    'wh_expenses.amount',
                    'dim_expense_types.category',
                    'dim_expense_types.category_override',
                ])
                ->get();

            $expensesTotal = (float) $expenses->sum('amount');
            $laborCost = 0.0;
            $rentCost = 0.0;
            $otherExpenses = 0.0;
            $expensesByType = [];

            foreach ($expenses as $exp) {
                $category = $exp->category_override ?? $exp->category ?? 'other';
                $amount = (float) $exp->amount;

                if ($category === 'labor') {
                    $laborCost += $amount;
                } elseif ($category === 'rent') {
                    $rentCost += $amount;
                } else {
                    $otherExpenses += $amount;
                }

                $typeUuid = (string) ($exp->expense_type_uuid ?? '');
                if (! isset($expensesByType[$typeUuid])) {
                    $expensesByType[$typeUuid] = [
                        'category' => $category,
                        'amount' => 0.0,
                    ];
                }
                $expensesByType[$typeUuid]['amount'] += $amount;
            }

            // 5. Waste & Stocktaking
            $wasteCost = (float) DB::table('wh_wastes')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->sum('total');

            $inventoryVarianceCost = (float) DB::table('wh_stocktakings')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->sum('variance_cost');

            // 6. Purchases
            $purchases = DB::table('wh_purchases')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->get();

            $purchasesTotal = (float) $purchases->where('kind', 'invoice')->sum('total');
            $purchaseReturnsTotal = (float) $purchases->where('kind', 'return')->sum('total');

            // 7. Net profit
            $netProfit = $grossProfit - $expensesTotal - $wasteCost - $inventoryVarianceCost;

            // 8. Sales by type & Sales by method
            $salesByType = [];
            foreach ($completedOrders->groupBy('type') as $type => $typeOrders) {
                $salesByType[(string) $type] = [
                    'orders' => $typeOrders->count(),
                    'net_sales' => number_format(
                        (float) $typeOrders->sum(fn ($o) => (float) $o->total - (float) $o->tax),
                        2,
                        '.',
                        ''
                    ),
                ];
            }

            $salesByMethod = [];
            if ($completedOrders->isNotEmpty()) {
                $completedOrderUuids = $completedOrders->pluck('uuid')->all();
                $payments = DB::table('wh_payments')
                    ->where('branch_id', $branchId)
                    ->whereIn('order_uuid', $completedOrderUuids)
                    ->get();

                foreach ($payments->groupBy('method') as $method => $methodPayments) {
                    $salesByMethod[(string) $method] = number_format(
                        (float) $methodPayments->sum('amount'),
                        2,
                        '.',
                        ''
                    );
                }
            }

            // 9. Shifts
            $shifts = DB::table('wh_shifts')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->get();

            $shiftsCount = $shifts->count();
            $cashDeficitTotal = 0.0;
            foreach ($shifts as $shift) {
                if ($shift->closed) {
                    $cashDeficitTotal += max(0.0, (float) $shift->end_cash - (float) $shift->real_cash);
                }
            }

            // 10. Status & Reconciliation
            $isDayClosed = DB::table('wh_inventory_days')
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->exists()
                || DB::table('wh_day_summaries')
                    ->where('branch_id', $branchId)
                    ->where('business_date', $businessDate)
                    ->whereNull('deleted_at')
                    ->exists();

            $isReconciled = $this->reconciler->reconcile(
                $branchId,
                $businessDate,
                $organizationId,
                $ordersCount,
                $netSales,
                $expensesTotal,
                $wasteCost
            );

            // 11. Insert agg_branch_daily
            DB::table('agg_branch_daily')->insert([
                'organization_id' => $organizationId,
                'restaurant_id' => $restaurantId,
                'branch_id' => $branchId,
                'business_date' => $businessDate,
                'weekday' => $weekday,
                'orders_count' => $ordersCount,
                'cancelled_count' => $cancelledCount,
                'cancelled_value' => $cancelledValue,
                'gross_sales' => $grossSales,
                'discounts' => $discounts,
                'tax' => $tax,
                'service' => $service,
                'net_sales' => $netSales,
                'returns_amount' => $returnsAmount,
                'net_sales_after_returns' => $netSalesAfterReturns,
                'cogs' => $cogs,
                'returned_cogs_recovered' => $returnedCogsRecovered,
                'gross_profit' => $grossProfit,
                'expenses_total' => $expensesTotal,
                'labor_cost' => $laborCost,
                'rent_cost' => $rentCost,
                'other_expenses' => $otherExpenses,
                'waste_cost' => $wasteCost,
                'inventory_variance_cost' => $inventoryVarianceCost,
                'purchases_total' => $purchasesTotal,
                'purchase_returns_total' => $purchaseReturnsTotal,
                'net_profit' => $netProfit,
                'sales_by_type' => json_encode((object) $salesByType),
                'sales_by_method' => json_encode((object) $salesByMethod),
                'shifts_count' => $shiftsCount,
                'cash_deficit_total' => $cashDeficitTotal,
                'is_day_closed' => $isDayClosed,
                'is_reconciled' => $isReconciled,
                'computed_at' => now(),
            ]);

            // 12. agg_product_daily
            $orderItems = DB::table('wh_order_items')
                ->join('wh_orders', function ($join) {
                    $join->on('wh_order_items.branch_id', '=', 'wh_orders.branch_id')
                        ->on('wh_order_items.order_uuid', '=', 'wh_orders.uuid');
                })
                ->where('wh_orders.branch_id', $branchId)
                ->where('wh_orders.business_date', $businessDate)
                ->where('wh_orders.status', 'completed')
                ->whereNull('wh_orders.deleted_at')
                ->select([
                    'wh_order_items.product_uuid',
                    'wh_order_items.product_ref',
                    'wh_order_items.quantity',
                    'wh_order_items.price',
                    'wh_order_items.item_discount',
                    'wh_order_items.total',
                    'wh_order_items.unit_cost',
                ])
                ->get();

            $returnItems = DB::table('wh_order_return_items')
                ->join('wh_order_returns', function ($join) {
                    $join->on('wh_order_return_items.branch_id', '=', 'wh_order_returns.branch_id')
                        ->on('wh_order_return_items.return_uuid', '=', 'wh_order_returns.uuid');
                })
                ->join('wh_order_items', function ($join) {
                    $join->on('wh_order_return_items.branch_id', '=', 'wh_order_items.branch_id')
                        ->on('wh_order_return_items.order_item_uuid', '=', 'wh_order_items.uuid');
                })
                ->where('wh_order_returns.branch_id', $branchId)
                ->where('wh_order_returns.business_date', $businessDate)
                ->whereNull('wh_order_returns.deleted_at')
                ->select([
                    'wh_order_items.product_uuid',
                    'wh_order_return_items.quantity',
                ])
                ->get();

            $wasteItems = DB::table('wh_waste_items')
                ->join('wh_wastes', function ($join) {
                    $join->on('wh_waste_items.branch_id', '=', 'wh_wastes.branch_id')
                        ->on('wh_waste_items.waste_uuid', '=', 'wh_wastes.uuid');
                })
                ->where('wh_wastes.branch_id', $branchId)
                ->where('wh_wastes.business_date', $businessDate)
                ->whereNull('wh_wastes.deleted_at')
                ->select([
                    'wh_waste_items.product_uuid',
                    'wh_waste_items.quantity',
                    'wh_waste_items.total',
                ])
                ->get();

            $productUuids = array_values(array_unique(array_filter(array_merge(
                $orderItems->pluck('product_uuid')->all(),
                $returnItems->pluck('product_uuid')->all(),
                $wasteItems->pluck('product_uuid')->all()
            ))));

            if (! empty($productUuids)) {
                $dimProducts = DB::table('dim_products')
                    ->where('branch_id', $branchId)
                    ->whereIn('uuid', $productUuids)
                    ->whereNull('deleted_at')
                    ->get()
                    ->keyBy('uuid');

                $orderItemsByProduct = $orderItems->groupBy('product_uuid');
                $returnItemsByProduct = $returnItems->groupBy('product_uuid');
                $wasteItemsByProduct = $wasteItems->groupBy('product_uuid');

                $productRows = [];

                foreach ($productUuids as $pUuid) {
                    $pOrders = $orderItemsByProduct->get($pUuid, collect());
                    $pReturns = $returnItemsByProduct->get($pUuid, collect());
                    $pWastes = $wasteItemsByProduct->get($pUuid, collect());

                    $qtySold = (float) $pOrders->sum('quantity');
                    $grossSalesP = (float) $pOrders->sum(fn ($i) => (float) $i->quantity * (float) $i->price);
                    $itemDiscounts = (float) $pOrders->sum('item_discount');
                    $netSalesP = (float) $pOrders->sum('total');
                    $itemCogs = (float) $pOrders->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost);
                    $grossProfitP = $netSalesP - $itemCogs;

                    $returnsQty = (float) $pReturns->sum('quantity');
                    $wasteQty = (float) $pWastes->sum('quantity');
                    $wasteCostP = (float) $pWastes->sum('total');

                    // A product appears if any of these is non-zero
                    if (
                        abs($qtySold) < 0.0001
                        && abs($grossSalesP) < 0.005
                        && abs($itemDiscounts) < 0.005
                        && abs($netSalesP) < 0.005
                        && abs($itemCogs) < 0.005
                        && abs($returnsQty) < 0.0001
                        && abs($wasteQty) < 0.0001
                        && abs($wasteCostP) < 0.005
                    ) {
                        continue;
                    }

                    $dim = $dimProducts->get($pUuid);
                    $productRef = $dim !== null ? $dim->product_ref : $pOrders->first()?->product_ref;
                    $categoryUuid = $dim !== null ? $dim->category_uuid : null;

                    $productRows[] = [
                        'organization_id' => $organizationId,
                        'branch_id' => $branchId,
                        'business_date' => $businessDate,
                        'product_uuid' => $pUuid,
                        'category_uuid' => $categoryUuid,
                        'product_ref' => $productRef,
                        'qty_sold' => $qtySold,
                        'gross_sales' => $grossSalesP,
                        'item_discounts' => $itemDiscounts,
                        'net_sales' => $netSalesP,
                        'cogs' => $itemCogs,
                        'gross_profit' => $grossProfitP,
                        'returns_qty' => $returnsQty,
                        'waste_qty' => $wasteQty,
                        'waste_cost' => $wasteCostP,
                    ];
                }

                if (! empty($productRows)) {
                    DB::table('agg_product_daily')->insert($productRows);
                }
            }

            // 13. agg_shift
            $shiftRows = [];
            foreach ($shifts as $shift) {
                $shiftOrders = DB::table('wh_orders')
                    ->where('branch_id', $branchId)
                    ->where('shift_uuid', $shift->uuid)
                    ->whereNull('deleted_at')
                    ->get();

                $completedShiftOrders = $shiftOrders->where('status', 'completed');
                $cancelledShiftOrders = $shiftOrders->where('status', 'cancelled');

                $sOrdersCount = $completedShiftOrders->count();
                $sCancelledCount = $cancelledShiftOrders->count();
                $sNetSales = (float) $completedShiftOrders->sum(fn ($o) => (float) $o->total - (float) $o->tax);
                $sCogs = (float) $completedShiftOrders->sum('cogs');
                $sGrossProfit = $sNetSales - $sCogs;
                $sAvgTicket = $sOrdersCount > 0 ? round($sNetSales / $sOrdersCount, 2) : null;

                $durationMinutes = null;
                if ($shift->start_at && $shift->end_at) {
                    $start = Carbon::parse($shift->start_at);
                    $end = Carbon::parse($shift->end_at);
                    if ($end->lessThan($start)) {
                        $end = $end->addDay();
                    }
                    $durationMinutes = (int) $start->diffInMinutes($end);
                }

                $sCashDeficit = 0.0;
                if ($shift->closed) {
                    $sCashDeficit = max(0.0, (float) $shift->end_cash - (float) $shift->real_cash);
                }

                $shiftRows[] = [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'shift_uuid' => $shift->uuid,
                    'business_date' => $businessDate,
                    'weekday' => $weekday,
                    'start_at' => $shift->start_at,
                    'end_at' => $shift->end_at,
                    'duration_minutes' => $durationMinutes,
                    'cashier_uuid' => $shift->user_uuid,
                    'orders_count' => $sOrdersCount,
                    'net_sales' => $sNetSales,
                    'gross_profit' => $sGrossProfit,
                    'avg_ticket' => $sAvgTicket,
                    'cancelled_count' => $sCancelledCount,
                    'cash_deficit' => $sCashDeficit,
                ];
            }

            if (! empty($shiftRows)) {
                DB::table('agg_shift')->insert($shiftRows);
            }

            // 14. agg_branch_hourly
            $hourlyRows = [];
            foreach ($completedOrders->groupBy('hour_of_day') as $hour => $hOrders) {
                $hourlyRows[] = [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'hour' => (int) $hour,
                    'weekday' => $weekday,
                    'orders_count' => $hOrders->count(),
                    'net_sales' => (float) $hOrders->sum(fn ($o) => (float) $o->total - (float) $o->tax),
                ];
            }

            if (! empty($hourlyRows)) {
                DB::table('agg_branch_hourly')->insert($hourlyRows);
            }

            // 15. agg_expense_daily
            $expenseDailyRows = [];
            foreach ($expensesByType as $typeUuid => $data) {
                $expenseDailyRows[] = [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'expense_type_uuid' => $typeUuid,
                    'category' => $data['category'],
                    'amount' => $data['amount'],
                ];
            }

            if (! empty($expenseDailyRows)) {
                DB::table('agg_expense_daily')->insert($expenseDailyRows);
            }

            // 16. agg_purchase_daily
            $purchaseDailyRows = [];
            foreach ($purchases->groupBy(fn ($p) => ($p->supplier_uuid ?? '').'||'.$p->kind) as $groupedPurchases) {
                $first = $groupedPurchases->first();
                $purchaseDailyRows[] = [
                    'organization_id' => $organizationId,
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'supplier_uuid' => (string) ($first->supplier_uuid ?? ''),
                    'kind' => (string) $first->kind,
                    'amount' => (float) $groupedPurchases->sum('total'),
                    'invoices_count' => $groupedPurchases->count(),
                ];
            }

            if (! empty($purchaseDailyRows)) {
                DB::table('agg_purchase_daily')->insert($purchaseDailyRows);
            }

            if (! DirtyDateOutbox::isFenceCurrent($branchId, $businessDate, $fenceToken)) {
                throw new FencedWorkerException("Recompute worker fenced: fence token {$fenceToken} superseded.");
            }
            DirtyDateOutbox::commitRecomputeFence($branchId, $businessDate, $fenceToken);

            // 17. Dispatch BranchDayUpdated
            BranchDayUpdated::dispatch($organizationId, $branchId, $businessDate);
        });
    }

    private function hasWarehouseData(int $branchId, string $businessDate): bool
    {
        $tables = [
            'wh_orders',
            'wh_order_returns',
            'wh_shifts',
            'wh_expenses',
            'wh_purchases',
            'wh_wastes',
            'wh_stocktakings',
            'wh_inventory_days',
            'wh_day_summaries',
        ];

        foreach ($tables as $table) {
            if (DB::table($table)
                ->where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->whereNull('deleted_at')
                ->exists()) {
                return true;
            }
        }

        return false;
    }
}
