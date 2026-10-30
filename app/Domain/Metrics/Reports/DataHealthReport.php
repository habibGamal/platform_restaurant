<?php

declare(strict_types=1);

namespace App\Domain\Metrics\Reports;

use App\Domain\Metrics\Completeness;
use App\Domain\Metrics\MetricsQuery;
use App\Domain\Metrics\Period\Period;
use App\Domain\Metrics\Reports\DataHealthReport\BranchCalendarRow;
use App\Domain\Metrics\Reports\DataHealthReport\BranchHealthRow;
use App\Domain\Metrics\Reports\DataHealthReport\CalendarDayCell;
use App\Domain\Metrics\Reports\DataHealthReport\DataHealthSummary;
use App\Domain\Metrics\Reports\DataHealthReport\ProfitDriftRow;
use App\Domain\Metrics\Reports\DataHealthReport\ReconciliationIssueRow;
use App\Domain\Metrics\Reports\DataHealthReport\RejectionLogRow;
use App\Models\Branch;
use App\Models\BranchCommand;
use App\Models\ReconciliationIssue;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class DataHealthReport
{
    public function __construct(
        public readonly MetricsQuery $query,
    ) {}

    public static function for(MetricsQuery $query): self
    {
        return new self($query);
    }

    /**
     * Compute branch status based on heartbeat and pending count.
     * Rules:
     * - online: heartbeat < 10 min (and pending <= 500)
     * - delayed: 10–60 min or pending > 500
     * - offline: > 60 min or no heartbeat
     */
    public static function computeBranchStatus(?CarbonImmutable $lastHeartbeatAt, int $pendingCount, CarbonImmutable $now): string
    {
        if ($lastHeartbeatAt === null) {
            return 'offline';
        }

        $diffMinutes = (int) $lastHeartbeatAt->diffInMinutes($now);

        if ($diffMinutes > 60) {
            return 'offline';
        }

        if ($diffMinutes >= 10 || $pendingCount > 500) {
            return 'delayed';
        }

        return 'online';
    }

    /**
     * Check if a POS version is outdated compared to the latest known version.
     */
    public static function isPosVersionOutdated(?string $version, ?string $latestVersion): bool
    {
        if ($version === null || $latestVersion === null) {
            return false;
        }

        return version_compare($version, $latestVersion, '<');
    }

    /**
     * Format time difference as a friendly Arabic string.
     */
    public static function formatAge(?CarbonImmutable $from, CarbonImmutable $to): ?string
    {
        if ($from === null) {
            return null;
        }

        $diffMinutes = (int) $from->diffInMinutes($to);

        if ($diffMinutes < 1) {
            return 'الآن';
        }

        if ($diffMinutes < 60) {
            return "{$diffMinutes} دقيقة";
        }

        $hours = (int) floor($diffMinutes / 60);
        if ($hours < 24) {
            return "{$hours} ساعة";
        }

        $days = (int) floor($hours / 24);

        return "{$days} يوم";
    }

    /**
     * Overall health overview / summary stats.
     */
    public function summary(?Period $calendarPeriod = null): DataHealthSummary
    {
        $branches = $this->branchStatusTable();
        $totalBranches = count($branches);
        $onlineBranches = 0;
        $delayedBranches = 0;
        $offlineBranches = 0;

        foreach ($branches as $branch) {
            match ($branch->status) {
                'online' => $onlineBranches++,
                'delayed' => $delayedBranches++,
                default => $offlineBranches++,
            };
        }

        $calendar = $this->completenessCalendar($calendarPeriod);
        $coverage = $calendar['coverage'];

        $branchIds = $this->query->branchIds();
        $openIssuesCount = 0;
        $rejectedDocs7Days = 0;

        if (! empty($branchIds)) {
            $openIssuesCount = (int) DB::table('reconciliation_issues')
                ->whereIn('branch_id', $branchIds)
                ->whereIn('status', ['open', 'resync_requested'])
                ->count();

            $now = CarbonImmutable::now('Africa/Cairo');
            $sevenDaysAgo = $now->subDays(7)->toDateTimeString();

            $rejectedDocs7Days = (int) DB::table('ingest_rejections')
                ->whereIn('branch_id', $branchIds)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();
        }

        return new DataHealthSummary(
            totalBranches: $totalBranches,
            onlineBranches: $onlineBranches,
            delayedBranches: $delayedBranches,
            offlineBranches: $offlineBranches,
            totalDays: $coverage['total'],
            completeDays: $coverage['complete'],
            openReconciliationIssues: $openIssuesCount,
            rejectedDocs7Days: $rejectedDocs7Days,
        );
    }

    /**
     * Section 1: Branch status table.
     *
     * @return list<BranchHealthRow>
     */
    public function branchStatusTable(): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $now = CarbonImmutable::now('Africa/Cairo');

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get();

        $maxVersion = null;
        foreach ($branches as $branch) {
            $ver = $branch->pos_version;
            if ($ver !== null && $ver !== '') {
                if ($maxVersion === null || version_compare($ver, $maxVersion, '>')) {
                    $maxVersion = $ver;
                }
            }
        }

        $sevenDaysAgo = $now->subDays(7)->toDateTimeString();
        $rejections7Days = DB::table('ingest_rejections')
            ->whereIn('branch_id', $branchIds)
            ->where('created_at', '>=', $sevenDaysAgo)
            ->groupBy('branch_id')
            ->selectRaw('branch_id, count(*) as count')
            ->pluck('count', 'branch_id')
            ->all();

        $canSeeNames = $this->query->canSeeOtherBranchNames();
        $userBranchIds = $this->query->user->branches->pluck('id')->all();

        $result = [];
        foreach ($branches as $branch) {
            $branchId = (int) $branch->id;
            $branchName = $branch->name;

            if (! $canSeeNames && ! in_array($branchId, $userBranchIds, true)) {
                $branchName = "فرع #{$branchId}";
            }

            $lastHeartbeat = $branch->last_heartbeat_at !== null
                ? CarbonImmutable::parse($branch->last_heartbeat_at)->setTimezone('Africa/Cairo')
                : null;

            $lastBatch = $branch->last_batch_at !== null
                ? CarbonImmutable::parse($branch->last_batch_at)->setTimezone('Africa/Cairo')
                : null;

            $oldestPending = $branch->oldest_pending_at !== null
                ? CarbonImmutable::parse($branch->oldest_pending_at)->setTimezone('Africa/Cairo')
                : null;

            $pendingCount = (int) $branch->pending_count;
            $failedCount = (int) $branch->failed_count;
            $schemaVersion = $branch->schema_version !== null ? (int) $branch->schema_version : null;
            $posVersion = $branch->pos_version;

            $status = self::computeBranchStatus($lastHeartbeat, $pendingCount, $now);
            $isOutdated = self::isPosVersionOutdated($posVersion, $maxVersion);
            $rejCount = (int) ($rejections7Days[$branchId] ?? 0);

            $result[] = new BranchHealthRow(
                branchId: $branchId,
                branchName: $branchName,
                status: $status,
                lastHeartbeatAt: $lastHeartbeat,
                lastHeartbeatFormatted: self::formatAge($lastHeartbeat, $now),
                lastBatchAt: $lastBatch,
                lastBatchFormatted: self::formatAge($lastBatch, $now),
                posVersion: $posVersion,
                isOutdated: $isOutdated,
                schemaVersion: $schemaVersion,
                pendingCount: $pendingCount,
                oldestPendingAt: $oldestPending,
                oldestPendingAge: self::formatAge($oldestPending, $now),
                failedCount: $failedCount,
                rejectedDocs7Days: $rejCount,
            );
        }

        return $result;
    }

    /**
     * Section 2: Completeness calendar.
     * Branches × dates (last 30 days or given period), each cell = day status.
     *
     * @return array{
     *     branches: list<BranchCalendarRow>,
     *     dates: list<string>,
     *     coverage: array{complete: int, total: int}
     * }
     */
    public function completenessCalendar(?Period $period = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [
                'branches' => [],
                'dates' => [],
                'coverage' => ['complete' => 0, 'total' => 0],
            ];
        }

        $calendarPeriod = $period ?? $this->query->filters->period;

        if ($calendarPeriod->days() > 30) {
            $calendarPeriod = new Period(
                $calendarPeriod->to->subDays(29),
                $calendarPeriod->to
            );
        }

        $dates = array_values(array_map(
            fn (CarbonImmutable $d): string => $d->toDateString(),
            $calendarPeriod->dates()
        ));

        $completenessResult = Completeness::for($this->query, $calendarPeriod);

        $canSeeNames = $this->query->canSeeOtherBranchNames();
        $userBranchIds = $this->query->user->branches->pluck('id')->all();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->get()
            ->keyBy('id');

        $rows = [];
        $totalCells = 0;
        $completeCells = 0;

        foreach ($branchIds as $branchId) {
            $branch = $branches->get($branchId);
            $branchName = $branch !== null ? $branch->name : "فرع #{$branchId}";

            if (! $canSeeNames && ! in_array($branchId, $userBranchIds, true)) {
                $branchName = "فرع #{$branchId}";
            }

            $dayCells = [];
            $branchCompleteDays = 0;
            $branchTotalDays = 0;

            foreach ($dates as $date) {
                $status = $completenessResult->statusFor($branchId, $date);

                if ($status !== 'not_opened') {
                    $branchTotalDays++;
                    $totalCells++;

                    if ($status === 'complete') {
                        $branchCompleteDays++;
                        $completeCells++;
                    }
                }

                $dayCells[$date] = new CalendarDayCell(
                    date: $date,
                    status: $status,
                    branchId: $branchId,
                    label: $date,
                );
            }

            $rows[] = new BranchCalendarRow(
                branchId: $branchId,
                branchName: $branchName,
                completeDays: $branchCompleteDays,
                totalDays: $branchTotalDays,
                days: $dayCells,
            );
        }

        return [
            'branches' => $rows,
            'dates' => $dates,
            'coverage' => [
                'complete' => $completeCells,
                'total' => $totalCells,
            ],
        ];
    }

    /**
     * Section 3: Reconciliation issues.
     *
     * @return list<ReconciliationIssueRow>
     */
    public function reconciliationIssues(?Period $period = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $query = DB::table('reconciliation_issues')
            ->whereIn('branch_id', $branchIds);

        if ($period !== null) {
            $query->whereBetween('business_date', [
                $period->from->toDateString(),
                $period->to->toDateString(),
            ]);
        }

        $rawIssues = $query->orderByDesc('business_date')
            ->orderBy('branch_id')
            ->get();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id')
            ->all();

        $canSeeNames = $this->query->canSeeOtherBranchNames();
        $userBranchIds = $this->query->user->branches->pluck('id')->all();

        $fieldLabels = [
            'net_total' => 'صافي المبيعات',
            'orders_count' => 'عدد الطلبات',
            'expenses_total' => 'المصروفات',
            'waste_total' => 'الهالك',
            'cogs_total' => 'تكلفة المبيعات',
            'discount_total' => 'الخصومات',
        ];

        $statusLabels = [
            'open' => 'مفتوحة',
            'resync_requested' => 'طُلب إعادة مزامنة',
            'resolved' => 'تمت المعالجة',
            'failed' => 'فشلت',
        ];

        $result = [];
        foreach ($rawIssues as $issue) {
            $branchId = (int) $issue->branch_id;
            $branchName = $branches[$branchId] ?? "فرع #{$branchId}";

            if (! $canSeeNames && ! in_array($branchId, $userBranchIds, true)) {
                $branchName = "فرع #{$branchId}";
            }

            $posVal = $issue->pos_value !== null ? (float) $issue->pos_value : null;
            $platVal = $issue->platform_value !== null ? (float) $issue->platform_value : null;
            $diff = abs((float) ($posVal ?? 0.0) - (float) ($platVal ?? 0.0));

            $field = (string) $issue->field;
            $status = (string) $issue->status;

            $result[] = new ReconciliationIssueRow(
                id: (int) $issue->id,
                branchId: $branchId,
                branchName: $branchName,
                date: (string) $issue->business_date,
                field: $field,
                fieldLabel: $fieldLabels[$field] ?? $field,
                posValue: $posVal,
                platformValue: $platVal,
                diff: $diff,
                attempts: (int) $issue->attempts,
                status: $status,
                statusLabel: $statusLabels[$status] ?? $status,
            );
        }

        return $result;
    }

    /**
     * Action: Request Resync ("طلب إعادة مزامنة").
     * Creates a pending branch_commands row {type: 'resync', payload: {from, to}}.
     * Requires permission 'branches.manage'.
     */
    public function requestResync(int $issueId, ?User $user = null): BranchCommand
    {
        $actor = $user ?? $this->query->user;
        if (! $actor->can('branches.manage')) {
            throw new AuthorizationException('غير مصرح لك بطلب إعادة المزامنة.');
        }

        $branchIds = $this->query->branchIds();

        /** @var ReconciliationIssue $issue */
        $issue = ReconciliationIssue::withoutGlobalScopes()
            ->whereIn('branch_id', $branchIds)
            ->findOrFail($issueId);

        $dateString = CarbonImmutable::parse($issue->business_date)->toDateString();

        $command = BranchCommand::create([
            'branch_id' => $issue->branch_id,
            'type' => 'resync',
            'payload' => [
                'from' => $dateString,
                'to' => $dateString,
            ],
            'delivered_at' => null,
        ]);

        $issue->update([
            'status' => 'resync_requested',
            'attempts' => $issue->attempts + 1,
        ]);

        return $command;
    }

    /**
     * Section 4: Rejections log.
     *
     * @return list<RejectionLogRow>
     */
    public function rejectionsLog(?Period $period = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $query = DB::table('ingest_rejections')
            ->whereIn('branch_id', $branchIds);

        if ($period !== null) {
            $query->whereBetween('created_at', [
                $period->from->startOfDay()->toDateTimeString(),
                $period->to->endOfDay()->toDateTimeString(),
            ]);
        }

        $rawRows = $query->selectRaw('
                branch_id,
                doc_type,
                doc_uuid,
                reason,
                pos_version,
                MIN(created_at) as first_seen_at,
                MAX(created_at) as last_seen_at,
                COUNT(*) as count
            ')
            ->groupBy('branch_id', 'doc_type', 'doc_uuid', 'reason', 'pos_version')
            ->orderByDesc('last_seen_at')
            ->get();

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id')
            ->all();

        $canSeeNames = $this->query->canSeeOtherBranchNames();
        $userBranchIds = $this->query->user->branches->pluck('id')->all();

        $result = [];
        foreach ($rawRows as $row) {
            $branchId = (int) $row->branch_id;
            $branchName = $branches[$branchId] ?? "فرع #{$branchId}";

            if (! $canSeeNames && ! in_array($branchId, $userBranchIds, true)) {
                $branchName = "فرع #{$branchId}";
            }

            $firstSeen = $row->first_seen_at !== null
                ? CarbonImmutable::parse($row->first_seen_at)->setTimezone('Africa/Cairo')
                : null;

            $lastSeen = $row->last_seen_at !== null
                ? CarbonImmutable::parse($row->last_seen_at)->setTimezone('Africa/Cairo')
                : null;

            $result[] = new RejectionLogRow(
                branchId: $branchId,
                branchName: $branchName,
                docType: (string) $row->doc_type,
                docUuid: $row->doc_uuid !== null ? (string) $row->doc_uuid : null,
                reason: (string) $row->reason,
                posVersion: $row->pos_version !== null ? (string) $row->pos_version : null,
                firstSeenAt: $firstSeen,
                lastSeenAt: $lastSeen,
                count: (int) $row->count,
            );
        }

        return $result;
    }

    /**
     * Section 5: POS vs platform profit check.
     * Days where Σ profit_pos differs from platform K09 (gross_profit) by > 1%.
     *
     * @return list<ProfitDriftRow>
     */
    public function profitDriftCheck(?Period $period = null): array
    {
        $branchIds = $this->query->branchIds();
        if (empty($branchIds)) {
            return [];
        }

        $queryPeriod = $period ?? $this->query->filters->period;

        $posOrders = DB::table('wh_orders')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [
                $queryPeriod->from->toDateString(),
                $queryPeriod->to->toDateString(),
            ])
            ->where('status', 'completed')
            ->whereNull('deleted_at')
            ->groupBy('branch_id', 'business_date')
            ->havingRaw('COUNT(profit_pos) > 0')
            ->selectRaw('branch_id, business_date, COALESCE(SUM(profit_pos), 0) as pos_profit')
            ->get()
            ->keyBy(fn (object $row): string => "{$row->branch_id}:{$row->business_date}");

        $platformAggs = DB::table('agg_branch_daily')
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('business_date', [
                $queryPeriod->from->toDateString(),
                $queryPeriod->to->toDateString(),
            ])
            ->get()
            ->keyBy(fn (object $row): string => "{$row->branch_id}:{$row->business_date}");

        $branches = Branch::withoutGlobalScopes()
            ->whereIn('id', $branchIds)
            ->pluck('name', 'id')
            ->all();

        $canSeeNames = $this->query->canSeeOtherBranchNames();
        $userBranchIds = $this->query->user->branches->pluck('id')->all();

        $driftRows = [];
        foreach ($posOrders as $key => $posOrderRow) {
            [$branchIdStr, $date] = explode(':', $key, 2);
            $branchId = (int) $branchIdStr;

            $aggRow = $platformAggs->get($key);

            $posProfit = (float) $posOrderRow->pos_profit;
            $platformProfit = $aggRow !== null ? (float) $aggRow->gross_profit : 0.0;

            $diff = abs($posProfit - $platformProfit);
            $denominator = abs($platformProfit);

            $diffPercent = $denominator > 0.01
                ? ($diff / $denominator) * 100.0
                : ($diff > 0.01 ? 100.0 : 0.0);

            // Flag if drift is greater than 1%
            if ($diffPercent > 1.0) {
                $branchName = $branches[$branchId] ?? "فرع #{$branchId}";
                if (! $canSeeNames && ! in_array($branchId, $userBranchIds, true)) {
                    $branchName = "فرع #{$branchId}";
                }

                $driftRows[] = new ProfitDriftRow(
                    branchId: $branchId,
                    branchName: $branchName,
                    date: $date,
                    posProfit: $posProfit,
                    platformProfit: $platformProfit,
                    diff: $diff,
                    diffPercent: round($diffPercent, 2),
                );
            }
        }

        return $driftRows;
    }
}
