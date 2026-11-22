<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Assistant\AiAccess;
use App\Domain\Menu\Intelligence\AffinityMiner;
use App\Domain\Menu\Intelligence\ComboCandidateGenerator;
use App\Domain\Menu\Intelligence\IntelligenceClassifier;
use App\Domain\Menu\Intelligence\ItemProfileBuilder;
use App\Domain\Menu\Intelligence\ItemRanker;
use App\Domain\Menu\Intelligence\ItemStatsBuilder;
use App\Domain\Menu\Intelligence\PeriodKeys;
use App\Models\Menu\MenuIntelligenceBuild;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Rebuilds the organization's menu intelligence: labels, item profiles, then per period the stats,
 * basket itemsets, rankings and combo candidates the assistant reads instead of computing live.
 * Each finished period gets a menu_intelligence_builds marker, which is what readers treat as "ready".
 *
 * Unique until processing, so a dispatch arriving mid-build queues one more build instead of being
 * dropped; WithoutOverlapping keeps two builds of one organization from running at the same time.
 */
class BuildMenuIntelligence implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    // Below the assistant connection's retry_after (960 s), so a running build is never handed out twice.
    public int $timeout = 900;

    public int $maxExceptions = 1;

    /** Seconds the unique lock is held at most, so a killed worker cannot block the organization for good. */
    public int $uniqueFor = 3600;

    /** @var list<array{step: string, period: string|null, rows: int, ms: int}> */
    private array $timings = [];

    /**
     * @param  list<string>|null  $periodKeys  null = PeriodKeys::forBuild(today)
     * @param  int|null  $classifierCalls  model calls the classifier may make (null = no limit)
     */
    public function __construct(
        public int $organizationId,
        public ?array $periodKeys = null,
        public ?int $classifierCalls = null,
    ) {
        $this->onConnection(RunAssistantQuestion::QUEUE)->onQueue(RunAssistantQuestion::QUEUE);
    }

    /**
     * Waiting for another build of the organization releases the job; that is not a failure (the worker
     * runs --tries=1), so attempts are bounded by time instead of count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    /** Per mode, so a pending capped (ingest/question) build never swallows the nightly full one. */
    public function uniqueId(): string
    {
        return $this->organizationId.($this->classifierCalls === null ? ':full' : ':capped');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->organizationId))->releaseAfter(60)->expireAfter(1200)];
    }

    public function handle(
        IntelligenceClassifier $classifier,
        ItemProfileBuilder $profiles,
        ItemStatsBuilder $stats,
        AffinityMiner $affinities,
        ItemRanker $rankings,
        ComboCandidateGenerator $combos,
    ): void {
        $this->timings = [];
        $organization = Organization::query()->find($this->organizationId);

        if ($organization === null) {
            return;
        }

        $buildId = (string) Str::ulid();
        $today = CarbonImmutable::today();

        // A model failure must not stop the deterministic steps; they use whatever labels exist.
        $this->step('classify', null, function () use ($classifier, $organization): int {
            try {
                $result = $classifier->classify($organization, $this->classifierCalls);

                return $result['components'] + $result['categories'];
            } catch (Throwable $e) {
                report($e);

                return 0;
            }
        });

        $this->step('profiles', null, fn (): int => $profiles->build($organization, $today));

        foreach ($this->periodKeys ?? PeriodKeys::forBuild($today) as $period) {
            $rows = [
                'stats' => $this->step('stats', $period, fn (): int => $stats->build($organization, $period, $today, $buildId)),
                'affinities' => $this->step('affinities', $period, fn (): int => $affinities->build($organization, $period, $today, $buildId)),
                'rankings' => $this->step('rankings', $period, fn (): int => $rankings->build($organization, $period, $today, $buildId)),
                'combos' => $this->step('combos', $period, fn (): int => $combos->build($organization, $period, $today, $buildId)),
            ];

            // Written even when the period had no sales: readers tell "ready, no data" from "still preparing" by it.
            MenuIntelligenceBuild::withoutGlobalScopes()->updateOrCreate(
                ['organization_id' => $organization->id, 'period_key' => $period],
                ['build_id' => $buildId, 'rows' => $rows, 'completed_at' => now()],
            );
        }
    }

    /**
     * Steps of the last handle() run, in order.
     *
     * @return list<array{step: string, period: string|null, rows: int, ms: int}>
     */
    public function timings(): array
    {
        return $this->timings;
    }

    /** Queue one job for every organization that has AI access. */
    public static function dispatchForEligibleOrganizations(): void
    {
        $access = app(AiAccess::class);

        Organization::query()
            ->where('ai_enabled', true)
            ->each(function (Organization $organization) use ($access): void {
                if ($access->allowsOrganization($organization)) {
                    self::dispatch($organization->id);
                }
            });
    }

    /**
     * @param  callable(): int  $run  returns the rows written
     * @return int the rows written
     */
    private function step(string $step, ?string $period, callable $run): int
    {
        $startedAt = hrtime(true);
        $rows = $run();
        $ms = (int) round((hrtime(true) - $startedAt) / 1e6);

        $this->timings[] = ['step' => $step, 'period' => $period, 'rows' => $rows, 'ms' => $ms];

        Log::info('Menu intelligence build step', [
            'organization_id' => $this->organizationId,
            'step' => $step,
            'period' => $period,
            'rows' => $rows,
            'ms' => $ms,
        ]);

        return $rows;
    }
}
