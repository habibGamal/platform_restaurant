<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Metrics\Period\Period;
use App\Models\Branch;
use Carbon\CarbonImmutable;

final readonly class CompletenessResult
{
    /**
     * @param  list<int>  $branchIds
     * @param  array<int, Branch>  $branches
     * @param  array<string, object>  $dailyRows  keyed by "branchId:Y-m-d"
     */
    public function __construct(
        public array $branchIds,
        public Period $period,
        public string $today,
        public array $branches,
        public array $dailyRows,
    ) {}

    /**
     * Completeness status for a branch on a given date.
     * Rules from conventions §5:
     * - complete = row with is_day_closed AND is_reconciled
     * - closed = is_day_closed but not reconciled
     * - open = date is today (Africa/Cairo) and not closed
     * - missing = branch active, date >= opened_on, date < today and no closed row
     * - not_opened = before opened_on
     *
     * @return 'complete'|'closed'|'open'|'missing'|'not_opened'
     */
    public function statusFor(int $branchId, string $date): string
    {
        $branch = $this->branches[$branchId] ?? null;

        $openedOn = null;
        $isActive = true;

        if ($branch !== null) {
            $isActive = ($branch->getRawOriginal('status') === 'active');
            if ($branch->opened_on !== null) {
                $openedOn = CarbonImmutable::parse($branch->opened_on)->toDateString();
            }
        }

        // Before opened_on or branch not active before opened_on
        if ($openedOn !== null && $date < $openedOn) {
            return 'not_opened';
        }

        if (! $isActive && ($openedOn === null || $date < $openedOn)) {
            return 'not_opened';
        }

        $key = "{$branchId}:{$date}";
        $row = $this->dailyRows[$key] ?? null;

        if ($row !== null) {
            $isClosed = (bool) ($row->is_day_closed ?? false);
            $isReconciled = (bool) ($row->is_reconciled ?? false);

            if ($isClosed && $isReconciled) {
                return 'complete';
            }

            if ($isClosed) {
                return 'closed';
            }

            // Not closed
            if ($date === $this->today) {
                return 'open';
            }

            if ($date < $this->today) {
                return $isActive ? 'missing' : 'not_opened';
            }
        }

        // No row in agg_branch_daily
        if ($date === $this->today) {
            return 'open';
        }

        if ($date < $this->today) {
            return $isActive ? 'missing' : 'not_opened';
        }

        return 'open';
    }

    /**
     * Whether a branch is complete over the period (no missing, no closed/unreconciled days).
     */
    public function isBranchComplete(int $branchId): bool
    {
        $dates = $this->period->dates();
        $applicableDays = 0;

        foreach ($dates as $date) {
            $dateStr = $date->toDateString();
            $status = $this->statusFor($branchId, $dateStr);

            if ($status === 'not_opened') {
                continue;
            }

            $applicableDays++;

            if ($status === 'missing' || $status === 'closed') {
                return false;
            }
        }

        return $applicableDays > 0;
    }

    /**
     * Per-branch coverage statistics.
     *
     * @return array<int, array{complete: int, total: int, is_complete: bool}>
     */
    public function perBranchCoverage(): array
    {
        $coverage = [];
        $dates = $this->period->dates();

        foreach ($this->branchIds as $branchId) {
            $completeDays = 0;
            $totalDays = 0;

            foreach ($dates as $date) {
                $status = $this->statusFor($branchId, $date->toDateString());
                if ($status === 'not_opened') {
                    continue;
                }

                $totalDays++;
                if ($status === 'complete') {
                    $completeDays++;
                }
            }

            $isComplete = $this->isBranchComplete($branchId);

            $coverage[$branchId] = [
                'complete' => $completeDays,
                'total' => $totalDays,
                'is_complete' => $isComplete,
            ];
        }

        return $coverage;
    }

    /**
     * Overall completeness summary across selected branches: "X of Y branches complete".
     *
     * @return array{complete: int, total: int}
     */
    public function summary(): array
    {
        $completeCount = 0;
        foreach ($this->branchIds as $branchId) {
            if ($this->isBranchComplete($branchId)) {
                $completeCount++;
            }
        }

        return [
            'complete' => $completeCount,
            'total' => count($this->branchIds),
        ];
    }
}
