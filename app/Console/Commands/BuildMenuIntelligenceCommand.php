<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Menu\Intelligence\PeriodKeys;
use App\Jobs\BuildMenuIntelligence;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class BuildMenuIntelligenceCommand extends Command
{
    protected $signature = 'menu:build-intelligence
        {organization? : id}
        {--period=* : period keys}
        {--sync : run in-process}';

    protected $description = 'Build the menu intelligence tables (profiles, stats, itemsets, rankings, combos)';

    public function handle(): int
    {
        $organizationId = $this->argument('organization');
        $periods = array_values(array_filter(array_map('strval', (array) $this->option('period')), fn (string $p): bool => $p !== ''));

        if ($organizationId === null) {
            if ($this->option('sync') || $periods !== []) {
                $this->error('--sync and --period need an organization id.');

                return self::FAILURE;
            }

            BuildMenuIntelligence::dispatchForEligibleOrganizations();
            $this->info('Queued a build for every eligible organization.');

            return self::SUCCESS;
        }

        // Same rule as the builders (PeriodKeys::range), checked before any step runs.
        $today = CarbonImmutable::today();
        foreach ($periods as $period) {
            try {
                PeriodKeys::range($period, $today);
            } catch (InvalidArgumentException) {
                $this->error("Invalid period [{$period}]: use YYYY-MM for a month that has started (up to yesterday) or rolling_90d.");

                return self::FAILURE;
            }
        }

        if (! is_numeric($organizationId) || Organization::query()->find((int) $organizationId) === null) {
            $this->error("Organization {$organizationId} not found.");

            return self::FAILURE;
        }

        $job = new BuildMenuIntelligence((int) $organizationId, $periods === [] ? null : $periods);

        if (! $this->option('sync')) {
            dispatch($job);
            $this->info("Queued a build for organization {$organizationId}.");

            return self::SUCCESS;
        }

        app()->call([$job, 'handle']);

        $timings = $job->timings();
        $this->table(
            ['step', 'period', 'rows', 'ms'],
            array_map(fn (array $t): array => [$t['step'], $t['period'] ?? '-', $t['rows'], $t['ms']], $timings),
        );
        $this->info(sprintf('Total: %d ms, peak memory %.1f MB', array_sum(array_column($timings, 'ms')), memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }
}
