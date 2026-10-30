<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Kpi;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Granularity;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\BudgetVsActualDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\CategoryBranchMatrixDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\ExpenseListItemDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\ExpensesPurchasesKpisDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\ExpensesPurchasesReportData;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\ExpensesVsSalesGrowthDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\PurchasesPerBranchDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\PurchasesVsCogsDto;
use App\Domain\Metrics\Reports\ExpensesPurchasesReport\SupplierSummaryDto;
use App\Domain\Tenancy\AccessibleBranches;
use App\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class ExpensesPurchasesReport
{
    private ?ExpensesPurchasesReportData $cachedData = null;

    /** @var array<int, string> */
    private array $branchNames = [];

    public function __construct(
        public readonly MetricsQuery $query,
    ) {
        $this->resolveBranchNames();
    }

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    public function data(): ExpensesPurchasesReportData
    {
        if ($this->cachedData !== null) {
            return $this->cachedData;
        }

        $this->cachedData = new ExpensesPurchasesReportData(
            kpis: $this->kpis(),
            categoryMatrix: $this->categoryMatrix(),
            budgetVsActual: $this->budgetVsActual(),
            expensesVsSalesGrowth: $this->expensesVsSalesGrowth(),
            purchasesPerBranch: $this->purchasesPerBranch(),
            supplierSummary: $this->supplierSummary(),
            purchasesVsCogs: $this->purchasesVsCogs(),
            expenseList: $this->expenseList(),
            uncategorizedExpenseTypesCount: $this->uncategorizedExpenseTypesCount(),
        );

        return $this->cachedData;
    }

    public function kpis(): ExpensesPurchasesKpisDto
    {
        $period = $this->query->filters->period;
        $totals = $this->query->totals($period);
        $compTotals = $this->query->comparisonTotals();

        $totalExpenses = $totals->expenses_total;
        $totalExpensesComparison = $compTotals?->expenses_total;
        $totalExpensesGrowth = $compTotals !== null ? Kpi::growth($totalExpenses, $totalExpensesComparison) : null;

        $laborCost = $totals->labor_cost;
        $laborCostComparison = $compTotals?->labor_cost;
        $laborCostGrowth = $compTotals !== null ? Kpi::growth($laborCost, $laborCostComparison) : null;

        $netPurchases = $totals->netPurchases();
        $netPurchasesComparison = $compTotals?->netPurchases();
        $netPurchasesGrowth = $compTotals !== null ? Kpi::growth($netPurchases, $netPurchasesComparison) : null;

        $cogs = $totals->cogs - $totals->returned_cogs_recovered;
        $compCogs = $compTotals !== null ? ($compTotals->cogs - $compTotals->returned_cogs_recovered) : null;

        $purchasesToCogsRatio = $cogs > 0.0 ? round($netPurchases / $cogs, 4) : null;
        $purchasesToCogsRatioComparison = ($compCogs !== null && $compCogs > 0.0 && $netPurchasesComparison !== null)
            ? round($netPurchasesComparison / $compCogs, 4)
            : null;

        $netSalesAfterReturns = $totals->net_sales_after_returns;
        $compSales = $compTotals?->net_sales_after_returns;

        $expensesPercentOfSales = $netSalesAfterReturns > 0.0 ? round($totalExpenses / $netSalesAfterReturns, 4) : null;
        $expensesPercentOfSalesComparison = ($compSales !== null && $compSales > 0.0 && $totalExpensesComparison !== null)
            ? round($totalExpensesComparison / $compSales, 4)
            : null;

        return new ExpensesPurchasesKpisDto(
            totalExpenses: $totalExpenses,
            totalExpensesComparison: $totalExpensesComparison,
            totalExpensesGrowth: $totalExpensesGrowth,
            laborCost: $laborCost,
            laborCostComparison: $laborCostComparison,
            laborCostGrowth: $laborCostGrowth,
            netPurchases: $netPurchases,
            netPurchasesComparison: $netPurchasesComparison,
            netPurchasesGrowth: $netPurchasesGrowth,
            cogs: $cogs,
            purchasesToCogsRatio: $purchasesToCogsRatio,
            purchasesToCogsRatioComparison: $purchasesToCogsRatioComparison,
            netSalesAfterReturns: $netSalesAfterReturns,
            expensesPercentOfSales: $expensesPercentOfSales,
            expensesPercentOfSalesComparison: $expensesPercentOfSalesComparison,
        );
    }

    public function categoryMatrix(): CategoryBranchMatrixDto
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();
        $canSeeGroup = $this->query->canSeeGroup();

        $categoryLabels = [
            'labor' => 'العمالة',
            'rent' => 'الإيجار',
            'utilities' => 'المرافق',
            'marketing' => 'التسويق',
            'maintenance' => 'الصيانة',
            'operational' => 'تشغيلية',
            'supplies' => 'مستلزمات',
            'other' => 'أخرى',
        ];

        if (empty($branchIds)) {
            return new CategoryBranchMatrixDto(
                branches: [],
                categories: array_keys($categoryLabels),
                categoryLabels: $categoryLabels,
                cells: [],
                branchTotals: [],
                categoryTotals: [],
                grandTotal: null,
            );
        }

        $byBranch = $this->query->byBranch($period);

        $branches = [];
        foreach ($branchIds as $bId) {
            $branches[] = [
                'id' => $bId,
                'name' => $this->branchName($bId),
                'sales' => $byBranch[$bId]->net_sales_after_returns ?? 0.0,
            ];
        }

        $rows = $this->query->scoped('agg_expense_daily', $period)
            ->selectRaw('branch_id, category, COALESCE(SUM(amount), 0) as total_amount')
            ->groupBy('branch_id', 'category')
            ->get();

        /** @var array<string, array<int, float>> $cellAmounts */
        $cellAmounts = [];
        $presentCategories = [];

        foreach ($rows as $row) {
            $bId = (int) $row->branch_id;
            $cat = (string) $row->category;
            $amt = (float) $row->total_amount;

            $cellAmounts[$cat][$bId] = $amt;
            $presentCategories[$cat] = true;
        }

        // Ordered categories: standard ones first, then any extra present ones
        $orderedCategories = [];
        foreach (array_keys($categoryLabels) as $cat) {
            $orderedCategories[] = $cat;
        }
        foreach (array_keys($presentCategories) as $cat) {
            if (! in_array($cat, $orderedCategories, true)) {
                $orderedCategories[] = $cat;
                $categoryLabels[$cat] = $cat;
            }
        }

        /** @var array<string, array<int, array{amount: float, percentOfSales: ?float}>> $cells */
        $cells = [];
        /** @var array<int, array{amount: float, percentOfSales: ?float}> $branchTotals */
        $branchTotals = [];
        /** @var array<string, array{amount: float, percentOfSales: ?float}> $categoryTotals */
        $categoryTotals = [];

        $totalSalesAllBranches = 0.0;
        foreach ($branches as $b) {
            $totalSalesAllBranches += $b['sales'];
        }

        foreach ($branches as $b) {
            $bId = $b['id'];
            $bSales = $b['sales'];
            $bTotalAmount = 0.0;

            foreach ($orderedCategories as $cat) {
                $amt = $cellAmounts[$cat][$bId] ?? 0.0;
                $pct = $bSales > 0.0 ? round($amt / $bSales, 4) : null;
                $cells[$cat][$bId] = [
                    'amount' => $amt,
                    'percentOfSales' => $pct,
                ];
                $bTotalAmount += $amt;
            }

            $branchTotals[$bId] = [
                'amount' => $bTotalAmount,
                'percentOfSales' => $bSales > 0.0 ? round($bTotalAmount / $bSales, 4) : null,
            ];
        }

        $grandTotalAmount = 0.0;
        if ($canSeeGroup) {
            foreach ($orderedCategories as $cat) {
                $catTotal = 0.0;
                foreach ($branches as $b) {
                    $catTotal += $cellAmounts[$cat][$b['id']] ?? 0.0;
                }
                $categoryTotals[$cat] = [
                    'amount' => $catTotal,
                    'percentOfSales' => $totalSalesAllBranches > 0.0 ? round($catTotal / $totalSalesAllBranches, 4) : null,
                ];
                $grandTotalAmount += $catTotal;
            }

            $grandTotal = [
                'amount' => $grandTotalAmount,
                'percentOfSales' => $totalSalesAllBranches > 0.0 ? round($grandTotalAmount / $totalSalesAllBranches, 4) : null,
            ];
        } else {
            $grandTotal = null;
        }

        return new CategoryBranchMatrixDto(
            branches: $branches,
            categories: $orderedCategories,
            categoryLabels: $categoryLabels,
            cells: $cells,
            branchTotals: $branchTotals,
            categoryTotals: $categoryTotals,
            grandTotal: $grandTotal,
        );
    }

    /**
     * @return list<BudgetVsActualDto>
     */
    public function budgetVsActual(): array
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $expenseTypes = DB::table('dim_expense_types')
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('avg_month_rate')
            ->where('avg_month_rate', '>', 0)
            ->select('branch_id', 'uuid', 'name', 'category', 'category_override', 'avg_month_rate')
            ->orderBy('branch_id')
            ->orderBy('name')
            ->get();

        if ($expenseTypes->isEmpty()) {
            return [];
        }

        $spentRows = $this->query->scoped('agg_expense_daily', $period)
            ->selectRaw('branch_id, expense_type_uuid, COALESCE(SUM(amount), 0) as total_spent')
            ->groupBy('branch_id', 'expense_type_uuid')
            ->get()
            ->keyBy(fn ($r): string => $r->branch_id.'|'.$r->expense_type_uuid);

        $categoryLabels = [
            'labor' => 'عمالة',
            'rent' => 'إيجار',
            'utilities' => 'مرافق',
            'marketing' => 'تسويق',
            'maintenance' => 'صيانة',
            'operational' => 'تشغيلية',
            'supplies' => 'مستلزمات',
            'other' => 'أخرى',
        ];

        $days = $period->days();
        $daysInMonth = $period->from->daysInMonth;

        $result = [];
        foreach ($expenseTypes as $et) {
            $bId = (int) $et->branch_id;
            $uuid = (string) $et->uuid;
            $monthlyBudget = (float) $et->avg_month_rate;

            $proratedBudget = self::calculateProratedBudget($monthlyBudget, $days, $daysInMonth);

            $key = $bId.'|'.$uuid;
            $actualSpent = isset($spentRows[$key]) ? (float) $spentRows[$key]->total_spent : 0.0;
            $variance = round($actualSpent - $proratedBudget, 2);
            $consumptionPct = $proratedBudget > 0.0 ? round($actualSpent / $proratedBudget, 4) : null;
            $isOverBudget = $actualSpent > $proratedBudget;

            $effectiveCategory = (string) ($et->category_override ?? $et->category ?? 'other');

            $result[] = new BudgetVsActualDto(
                branchId: $bId,
                branchName: $this->branchName($bId),
                expenseTypeUuid: $uuid,
                expenseTypeName: (string) $et->name,
                category: $effectiveCategory,
                categoryLabel: $categoryLabels[$effectiveCategory] ?? $effectiveCategory,
                monthlyBudget: $monthlyBudget,
                proratedBudget: $proratedBudget,
                actualSpent: $actualSpent,
                variance: $variance,
                consumptionPct: $consumptionPct,
                isOverBudget: $isOverBudget,
            );
        }

        return $result;
    }

    public static function calculateProratedBudget(float $avgMonthRate, int $days, int $daysInMonth): float
    {
        if ($daysInMonth <= 0) {
            return 0.0;
        }

        return round($avgMonthRate * ($days / $daysInMonth), 2);
    }

    /**
     * @return list<ExpensesVsSalesGrowthDto>
     */
    public function expensesVsSalesGrowth(): array
    {
        $period = $this->query->filters->period;
        $compPeriod = $this->query->filters->comparison;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $currentByBranch = $this->query->byBranch($period);
        $compByBranch = $compPeriod !== null ? $this->query->byBranch($compPeriod) : [];

        $result = [];
        foreach ($branchIds as $bId) {
            $cur = $currentByBranch[$bId] ?? null;
            $comp = $compByBranch[$bId] ?? null;

            $currentExpenses = $cur !== null ? $cur->expenses_total : 0.0;
            $comparisonExpenses = $comp?->expenses_total;
            $expensesGrowth = $comparisonExpenses !== null
                ? Kpi::growth($currentExpenses, $comparisonExpenses)
                : null;

            $currentSales = $cur !== null ? $cur->net_sales_after_returns : 0.0;
            $comparisonSales = $comp?->net_sales_after_returns;
            $salesGrowth = $comparisonSales !== null
                ? Kpi::growth($currentSales, $comparisonSales)
                : null;

            $growthDelta = ($expensesGrowth !== null && $salesGrowth !== null)
                ? round($expensesGrowth - $salesGrowth, 4)
                : null;

            $isWarning = ($expensesGrowth !== null && $salesGrowth !== null && $expensesGrowth > $salesGrowth);

            $result[] = new ExpensesVsSalesGrowthDto(
                branchId: $bId,
                branchName: $this->branchName($bId),
                currentExpenses: $currentExpenses,
                comparisonExpenses: $comparisonExpenses,
                expensesGrowth: $expensesGrowth,
                currentSales: $currentSales,
                comparisonSales: $comparisonSales,
                salesGrowth: $salesGrowth,
                growthDelta: $growthDelta,
                isWarning: $isWarning,
            );
        }

        return $result;
    }

    /**
     * @return list<PurchasesPerBranchDto>
     */
    public function purchasesPerBranch(): array
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $purchaseRows = $this->query->scoped('agg_purchase_daily', $period)
            ->selectRaw('branch_id, kind, COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(invoices_count), 0) as total_count')
            ->groupBy('branch_id', 'kind')
            ->get();

        /** @var array<int, array{invoicesAmount: float, invoicesCount: int, returnsAmount: float, returnsCount: int}> $branchTotals */
        $branchTotals = [];
        foreach ($branchIds as $bId) {
            $branchTotals[$bId] = [
                'invoicesAmount' => 0.0,
                'invoicesCount' => 0,
                'returnsAmount' => 0.0,
                'returnsCount' => 0,
            ];
        }

        foreach ($purchaseRows as $row) {
            $bId = (int) $row->branch_id;
            if (! isset($branchTotals[$bId])) {
                continue;
            }
            $kind = (string) $row->kind;
            $amt = (float) $row->total_amount;
            $cnt = (int) $row->total_count;

            if ($kind === 'invoice') {
                $branchTotals[$bId]['invoicesAmount'] += $amt;
                $branchTotals[$bId]['invoicesCount'] += $cnt;
            } elseif ($kind === 'return') {
                $branchTotals[$bId]['returnsAmount'] += $amt;
                $branchTotals[$bId]['returnsCount'] += $cnt;
            }
        }

        // Weekly trend per branch
        $dailyRows = $this->query->scoped('agg_purchase_daily', $period)
            ->select('branch_id', 'business_date', 'kind', 'amount')
            ->orderBy('business_date')
            ->get();

        /** @var array<int, array<string, float>> $weeklyTrends */
        $weeklyTrends = [];
        foreach ($branchIds as $bId) {
            $weeklyTrends[$bId] = [];
        }

        foreach ($dailyRows as $row) {
            $bId = (int) $row->branch_id;
            if (! isset($weeklyTrends[$bId])) {
                continue;
            }
            $date = CarbonImmutable::parse($row->business_date);
            $weekStart = Granularity::week->bucketStart($date)->toDateString();
            $kind = (string) $row->kind;
            $amt = (float) $row->amount;

            if (! isset($weeklyTrends[$bId][$weekStart])) {
                $weeklyTrends[$bId][$weekStart] = 0.0;
            }

            if ($kind === 'invoice') {
                $weeklyTrends[$bId][$weekStart] += $amt;
            } elseif ($kind === 'return') {
                $weeklyTrends[$bId][$weekStart] -= $amt;
            }
        }

        $result = [];
        foreach ($branchIds as $bId) {
            $t = $branchTotals[$bId];
            $net = $t['invoicesAmount'] - $t['returnsAmount'];

            $result[] = new PurchasesPerBranchDto(
                branchId: $bId,
                branchName: $this->branchName($bId),
                invoicesAmount: $t['invoicesAmount'],
                invoicesCount: $t['invoicesCount'],
                returnsAmount: $t['returnsAmount'],
                returnsCount: $t['returnsCount'],
                netPurchases: $net,
                weeklyTrend: $weeklyTrends[$bId] ?? [],
            );
        }

        return $result;
    }

    /**
     * @return list<SupplierSummaryDto>
     */
    public function supplierSummary(): array
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $suppliers = DB::table('dim_suppliers')
            ->whereIn('branch_id', $branchIds)
            ->select('branch_id', 'uuid', 'name')
            ->get()
            ->keyBy(fn ($s): string => $s->branch_id.'|'.$s->uuid);

        $purchaseRows = $this->query->scoped('agg_purchase_daily', $period)
            ->selectRaw('branch_id, supplier_uuid, kind, COALESCE(SUM(amount), 0) as total_amount, COALESCE(SUM(invoices_count), 0) as total_count')
            ->groupBy('branch_id', 'supplier_uuid', 'kind')
            ->get();

        /** @var array<string, array{name: string, branches: array<int, bool>, invoicesCount: int, invoicesAmount: float, returnsAmount: float}> $grouped */
        $grouped = [];
        $grandNetPurchases = 0.0;

        foreach ($purchaseRows as $row) {
            $bId = (int) $row->branch_id;
            $supUuid = (string) $row->supplier_uuid;
            $kind = (string) $row->kind;
            $amt = (float) $row->total_amount;
            $cnt = (int) $row->total_count;

            if ($supUuid === '') {
                $supName = 'بدون مورد / غير محدد';
            } else {
                $key = $bId.'|'.$supUuid;
                $sup = $suppliers->get($key);
                $supName = $sup !== null ? (string) $sup->name : 'غير محدد';
            }

            $normKey = trim(mb_strtolower($supName));
            if ($normKey === '') {
                $normKey = 'غير محدد';
                $supName = 'غير محدد';
            }

            if (! isset($grouped[$normKey])) {
                $grouped[$normKey] = [
                    'name' => $supName,
                    'branches' => [],
                    'invoicesCount' => 0,
                    'invoicesAmount' => 0.0,
                    'returnsAmount' => 0.0,
                ];
            }

            $grouped[$normKey]['branches'][$bId] = true;

            if ($kind === 'invoice') {
                $grouped[$normKey]['invoicesCount'] += $cnt;
                $grouped[$normKey]['invoicesAmount'] += $amt;
                $grandNetPurchases += $amt;
            } elseif ($kind === 'return') {
                $grouped[$normKey]['returnsAmount'] += $amt;
                $grandNetPurchases -= $amt;
            }
        }

        $result = [];
        foreach ($grouped as $normKey => $data) {
            $net = $data['invoicesAmount'] - $data['returnsAmount'];
            $share = ($grandNetPurchases > 0.0) ? round($net / $grandNetPurchases, 4) : null;

            $branchNames = [];
            foreach (array_keys($data['branches']) as $bId) {
                $branchNames[] = $this->branchName((int) $bId);
            }

            $result[] = new SupplierSummaryDto(
                supplierKey: $normKey,
                supplierName: $data['name'],
                branchesCount: count($data['branches']),
                branchNames: $branchNames,
                invoicesCount: $data['invoicesCount'],
                invoicesAmount: $data['invoicesAmount'],
                returnsAmount: $data['returnsAmount'],
                netPurchases: $net,
                shareOfPurchases: $share,
            );
        }

        usort($result, fn (SupplierSummaryDto $a, SupplierSummaryDto $b): int => $b->netPurchases <=> $a->netPurchases);

        return $result;
    }

    /**
     * @return list<PurchasesVsCogsDto>
     */
    public function purchasesVsCogs(): array
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $byBranch = $this->query->byBranch($period);

        $result = [];
        foreach ($branchIds as $bId) {
            $kpi = $byBranch[$bId] ?? null;
            $netPurchases = $kpi?->netPurchases() ?? 0.0;
            $cogs = $kpi !== null ? ($kpi->cogs - $kpi->returned_cogs_recovered) : 0.0;

            $ratio = $cogs > 0.0 ? round($netPurchases / $cogs, 4) : null;

            if ($ratio === null) {
                $status = 'no_data';
                $statusLabel = '—';
                $tooltip = 'لا توجد بيانات تكلفة كافية لحساب نسبة المشتريات إلى تكلفة المبيعات.';
            } elseif ($ratio > 1.2) {
                $status = 'building_up';
                $statusLabel = 'المشتريات أعلى من التكلفة (تراكم مخزون أو هالك غير مسجل) ⚠️';
                $tooltip = 'المشتريات تفوق تكلفة المبيعات بصورة ملحوظة (أعلى من 120%)؛ إذا استمر هذا لعدة أسابيع فإنه يشير إلى تراكم مخزون أو وجود هالك غير مسجل.';
            } elseif ($ratio < 0.8) {
                $status = 'running_down';
                $statusLabel = 'المشتريات أقل من التكلفة (استهلاك من المخزون السابق) ℹ️';
                $tooltip = 'المشتريات أقل من تكلفة المبيعات (أقل من 80%)؛ المطعم يستهلك المواد من المخزون السابق المخزن في الفروع.';
            } else {
                $status = 'balanced';
                $statusLabel = 'متوازن ومستقر ✓';
                $tooltip = 'حجم المشتريات متناسب مع وتيرة الاستهلاك الفعلي وتكلفة المبيعات (بين 80% و120%).';
            }

            $result[] = new PurchasesVsCogsDto(
                branchId: $bId,
                branchName: $this->branchName($bId),
                netPurchases: $netPurchases,
                cogs: $cogs,
                ratio: $ratio,
                status: $status,
                statusLabel: $statusLabel,
                tooltip: $tooltip,
            );
        }

        return $result;
    }

    /**
     * @return list<ExpenseListItemDto>
     */
    public function expenseList(int $limit = 100): array
    {
        $period = $this->query->filters->period;
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return [];
        }

        $rows = DB::table('wh_expenses')
            ->whereIn('wh_expenses.branch_id', $branchIds)
            ->whereBetween('wh_expenses.business_date', [$period->from->toDateString(), $period->to->toDateString()])
            ->whereNull('wh_expenses.deleted_at')
            ->leftJoin('dim_expense_types', function ($join) {
                $join->on('wh_expenses.expense_type_uuid', '=', 'dim_expense_types.uuid')
                    ->on('wh_expenses.branch_id', '=', 'dim_expense_types.branch_id');
            })
            ->select(
                'wh_expenses.id',
                'wh_expenses.business_date',
                'wh_expenses.branch_id',
                'wh_expenses.shift_uuid',
                'wh_expenses.expense_type_uuid',
                'wh_expenses.amount',
                'wh_expenses.notes',
                'dim_expense_types.name as expense_type_name',
                'dim_expense_types.category',
                'dim_expense_types.category_override'
            )
            ->orderByDesc('wh_expenses.business_date')
            ->orderByDesc('wh_expenses.id')
            ->limit($limit)
            ->get();

        $categoryLabels = [
            'labor' => 'عمالة',
            'rent' => 'إيجار',
            'utilities' => 'مرافق',
            'marketing' => 'تسويق',
            'maintenance' => 'صيانة',
            'operational' => 'تشغيلية',
            'supplies' => 'مستلزمات',
            'other' => 'أخرى',
        ];

        $result = [];
        foreach ($rows as $row) {
            $bId = (int) $row->branch_id;
            $cat = (string) ($row->category_override ?? $row->category ?? 'other');

            $result[] = new ExpenseListItemDto(
                id: (int) $row->id,
                businessDate: (string) $row->business_date,
                branchId: $bId,
                branchName: $this->branchName($bId),
                shiftUuid: $row->shift_uuid !== null ? (string) $row->shift_uuid : null,
                expenseTypeUuid: $row->expense_type_uuid !== null ? (string) $row->expense_type_uuid : null,
                expenseTypeName: (string) ($row->expense_type_name ?? 'غير محدد'),
                category: $cat,
                categoryLabel: $categoryLabels[$cat] ?? $cat,
                amount: (float) $row->amount,
                notes: $row->notes !== null ? (string) $row->notes : null,
            );
        }

        return $result;
    }

    public function uncategorizedExpenseTypesCount(): int
    {
        $branchIds = $this->query->branchIds();

        if (empty($branchIds)) {
            return 0;
        }

        return DB::table('dim_expense_types')
            ->whereIn('branch_id', $branchIds)
            ->whereNull('category_override')
            ->where(function ($q) {
                $q->whereNull('category')->orWhere('category', 'other');
            })
            ->count();
    }

    private function resolveBranchNames(): void
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return;
        }

        $user = $this->query->user();
        $canSeeOtherBranchNames = $this->query->canSeeOtherBranchNames();
        $accessibleBranchIds = AccessibleBranches::ids($user);

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get();

        foreach ($branches as $branch) {
            $id = (int) $branch->id;
            if ($canSeeOtherBranchNames || in_array($id, $accessibleBranchIds, true)) {
                $this->branchNames[$id] = (string) $branch->name;
            } else {
                $this->branchNames[$id] = 'فرع '.$id;
            }
        }
    }

    private function branchName(int $branchId): string
    {
        return $this->branchNames[$branchId] ?? ('فرع '.$branchId);
    }
}
