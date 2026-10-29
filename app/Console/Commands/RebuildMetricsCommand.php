<?php

namespace App\Console\Commands;

use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Models\Branch;
use Carbon\CarbonPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RebuildMetricsCommand extends Command
{
    protected $signature = 'metrics:rebuild {--branch=*} {--from=} {--to=}';

    protected $description = 'Recompute aggregate metrics for branches and date range';

    public function handle(BranchDayAggregator $aggregator): int
    {
        $branchOptions = (array) $this->option('branch');
        $branchIds = [];

        if (! empty($branchOptions)) {
            foreach ($branchOptions as $opt) {
                foreach (explode(',', (string) $opt) as $id) {
                    $id = trim($id);
                    if (is_numeric($id)) {
                        $branchIds[] = (int) $id;
                    }
                }
            }
            $branchIds = array_values(array_unique($branchIds));
        } else {
            $branchIds = Branch::pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if (empty($branchIds)) {
            $this->info('No branches found to rebuild.');

            return self::SUCCESS;
        }

        $from = $this->option('from');
        $to = $this->option('to');

        if (! $from || ! $to) {
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

            $minDate = null;
            $maxDate = null;

            foreach ($tables as $table) {
                $min = DB::table($table)->whereIn('branch_id', $branchIds)->whereNull('deleted_at')->min('business_date');
                $max = DB::table($table)->whereIn('branch_id', $branchIds)->whereNull('deleted_at')->max('business_date');
                if ($min && ($minDate === null || $min < $minDate)) {
                    $minDate = (string) $min;
                }
                if ($max && ($maxDate === null || $max > $maxDate)) {
                    $maxDate = (string) $max;
                }
            }

            $from = $from ?: $minDate;
            $to = $to ?: $maxDate;
        }

        if (! $from || ! $to) {
            $this->info('No warehouse data dates found for the specified branches.');

            return self::SUCCESS;
        }

        if ($from > $to) {
            $this->error("Invalid date range: from ({$from}) is after to ({$to}).");

            return self::FAILURE;
        }

        $dates = CarbonPeriod::create($from, $to);

        foreach ($branchIds as $branchId) {
            foreach ($dates as $date) {
                $aggregator->recompute($branchId, $date->format('Y-m-d'));
            }
        }

        $this->info('Metrics rebuild complete.');

        return self::SUCCESS;
    }
}
