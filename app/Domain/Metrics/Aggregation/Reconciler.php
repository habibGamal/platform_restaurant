<?php

namespace App\Domain\Metrics\Aggregation;

use App\Models\BranchCommand;
use App\Models\ReconciliationIssue;
use Illuminate\Support\Facades\DB;

class Reconciler
{
    /**
     * Compare wh_day_summaries vs computed metrics (tolerance 0.01).
     *
     * @return bool True if day_summary exists and all fields match; false otherwise.
     */
    public function reconcile(
        int $branchId,
        string $businessDate,
        int $organizationId,
        int $ordersCount,
        float $netSales,
        float $expensesTotal,
        float $wasteCost
    ): bool {
        $daySummary = DB::table('wh_day_summaries')
            ->where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->whereNull('deleted_at')
            ->first();

        if (! $daySummary) {
            return false;
        }

        $mismatches = [];

        $ordersDiff = abs((float) $daySummary->completed_count - (float) $ordersCount);
        if ($ordersDiff > 0.01) {
            $mismatches['orders_count'] = [
                'pos' => $daySummary->completed_count,
                'platform' => $ordersCount,
            ];
        }

        $netDiff = abs((float) $daySummary->net_total - $netSales);
        if ($netDiff > 0.01) {
            $mismatches['net_total'] = [
                'pos' => $daySummary->net_total,
                'platform' => $netSales,
            ];
        }

        $expensesDiff = abs((float) $daySummary->expenses_total - $expensesTotal);
        if ($expensesDiff > 0.01) {
            $mismatches['expenses_total'] = [
                'pos' => $daySummary->expenses_total,
                'platform' => $expensesTotal,
            ];
        }

        $wasteDiff = abs((float) $daySummary->waste_total - $wasteCost);
        if ($wasteDiff > 0.01) {
            $mismatches['waste_total'] = [
                'pos' => $daySummary->waste_total,
                'platform' => $wasteCost,
            ];
        }

        if (empty($mismatches)) {
            ReconciliationIssue::where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->where('status', '!=', 'resolved')
                ->update(['status' => 'resolved']);

            return true;
        }

        $hasUndeliveredResync = BranchCommand::where('branch_id', $branchId)
            ->where('type', 'resync')
            ->whereNull('delivered_at')
            ->get()
            ->contains(function (BranchCommand $cmd) use ($businessDate) {
                /** @var mixed $payload */
                $payload = $cmd->getAttribute('payload');
                if (is_string($payload)) {
                    $payload = json_decode($payload, true);
                }

                return is_array($payload)
                    && ($payload['from'] ?? null) === $businessDate
                    && ($payload['to'] ?? null) === $businessDate;
            });

        $createdResyncCommand = false;

        foreach ($mismatches as $field => $vals) {
            $existing = ReconciliationIssue::where('branch_id', $branchId)
                ->where('business_date', $businessDate)
                ->where('field', $field)
                ->first();

            $attempts = $existing ? (int) $existing->attempts : 0;

            if ($attempts >= 3) {
                $status = 'failed';
            } else {
                if (! $hasUndeliveredResync) {
                    $attempts++;
                    $status = 'resync_requested';
                    if (! $createdResyncCommand) {
                        BranchCommand::create([
                            'branch_id' => $branchId,
                            'type' => 'resync',
                            'payload' => ['from' => $businessDate, 'to' => $businessDate],
                        ]);
                        $createdResyncCommand = true;
                    }
                } else {
                    $status = $existing ? $existing->status : 'open';
                }
            }

            ReconciliationIssue::updateOrCreate(
                [
                    'branch_id' => $branchId,
                    'business_date' => $businessDate,
                    'field' => $field,
                ],
                [
                    'organization_id' => $organizationId,
                    'pos_value' => $vals['pos'],
                    'platform_value' => $vals['platform'],
                    'status' => $status,
                    'attempts' => $attempts,
                ]
            );
        }

        $mismatchFields = array_keys($mismatches);
        ReconciliationIssue::where('branch_id', $branchId)
            ->where('business_date', $businessDate)
            ->whereNotIn('field', $mismatchFields)
            ->where('status', '!=', 'resolved')
            ->update(['status' => 'resolved']);

        return false;
    }
}
