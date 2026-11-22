<?php

declare(strict_types=1);

namespace Tests\Feature\Menu\Intelligence;

use App\Ai\Agents\ComponentClassifier;
use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Menu\Intelligence\PeriodKeys;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Jobs\BuildMenuIntelligence;
use App\Jobs\ClassifyItemRoles;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use App\Models\Menu\ComboCandidate;
use App\Models\Menu\ItemPeriodStat;
use App\Models\Menu\ItemProfile;
use App\Models\Menu\ItemRanking;
use App\Models\Menu\MenuIntelligenceBuild;
use App\Models\Organization;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\MenuFixture;
use Tests\TestCase;

class BuildMenuIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    /** The fixture spans 2026-08-18..2026-10-16, so the build covers 2026-07..2026-09, 2026-10 and rolling_90d. */
    private const TODAY = '2026-10-17';

    private function fixture(): MenuFixture
    {
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 03:00:00'));

        $fixture = MenuFixture::create();

        // Rankings and combos only take items with a trusted role.
        $roles = app(ItemRoles::class);
        $orgId = $fixture->organization->id;
        foreach (['ZORBA-PLATE', 'KELTO-WRAP', 'BRIMO-BOX', 'NADD-BOWL', 'FETA-TOWER', 'QUILL-STEW'] as $key) {
            $roles->setOwnerRole($orgId, $key, ItemRole::Main);
        }
        foreach (['LUMO-RICE', 'PEXA-SALAD'] as $key) {
            $roles->setOwnerRole($orgId, $key, ItemRole::Side);
        }
        foreach (['VIRA-SODA', 'TINY-SPRING'] as $key) {
            $roles->setOwnerRole($orgId, $key, ItemRole::Drink);
        }

        return $fixture;
    }

    /** Answer every component and category with a low-confidence default. */
    private function fakeModel(): void
    {
        ComponentClassifier::fake(function (string $prompt): array {
            $entries = json_decode(substr($prompt, (int) strpos($prompt, '[')), true);

            return ['items' => array_map(fn (array $e): array => str_contains($prompt, 'recipe components')
                ? ['key' => $e['key'], 'class' => 'other', 'confidence' => 0.40]
                : ['key' => $e['key'], 'season' => 'all', 'meal_type' => 'single', 'confidence' => 0.40], $entries)];
        });
    }

    /** @param  list<string>|null  $periodKeys */
    private function runJob(Organization $organization, ?array $periodKeys = null): BuildMenuIntelligence
    {
        $job = new BuildMenuIntelligence($organization->id, $periodKeys);
        app()->call([$job, 'handle']);

        return $job;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, int> period key => rows
     */
    private function rowsPerPeriod(string $model, int $orgId): array
    {
        return $model::withoutGlobalScopes()
            ->where('organization_id', $orgId)
            ->selectRaw('period_key, COUNT(*) AS n')
            ->groupBy('period_key')
            ->pluck('n', 'period_key')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    public function test_the_build_writes_profiles_stats_rankings_and_combos_for_every_period(): void
    {
        $fixture = $this->fixture();
        $this->fakeModel();
        $orgId = $fixture->organization->id;

        $job = $this->runJob($fixture->organization);

        $this->assertGreaterThan(0, ItemProfile::withoutGlobalScopes()->where('organization_id', $orgId)->count());

        $periods = PeriodKeys::forBuild(CarbonImmutable::parse(self::TODAY));
        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10', 'rolling_90d'], $periods);

        // July has no sales; the others all do. August's 14 days are too few for the rankings' days-on-sale floor.
        $stats = $this->rowsPerPeriod(ItemPeriodStat::class, $orgId);
        $rankings = $this->rowsPerPeriod(ItemRanking::class, $orgId);
        foreach (['2026-08', '2026-09', '2026-10', 'rolling_90d'] as $period) {
            $this->assertGreaterThan(0, $stats[$period] ?? 0, "stats {$period}");
        }
        foreach (['2026-09', '2026-10', 'rolling_90d'] as $period) {
            $this->assertGreaterThan(0, $rankings[$period] ?? 0, "rankings {$period}");
        }
        $this->assertGreaterThan(0, ComboCandidate::withoutGlobalScopes()->where('organization_id', $orgId)->count());

        // One build id across every table the run wrote.
        $buildIds = ItemPeriodStat::withoutGlobalScopes()->where('organization_id', $orgId)->distinct()->pluck('build_id')
            ->merge(ItemRanking::withoutGlobalScopes()->where('organization_id', $orgId)->distinct()->pluck('build_id'))
            ->unique();
        $this->assertCount(1, $buildIds);

        $steps = array_map(fn (array $t): string => $t['step'].'@'.($t['period'] ?? '-'), $job->timings());
        $expected = ['classify@-', 'profiles@-'];
        foreach ($periods as $period) {
            foreach (['stats', 'affinities', 'rankings', 'combos'] as $step) {
                $expected[] = "{$step}@{$period}";
            }
        }
        $this->assertSame($expected, $steps);

        // A completion marker per period, July included: built with no sales, it is ready rather than preparing.
        $markers = MenuIntelligenceBuild::withoutGlobalScopes()->where('organization_id', $orgId)->get()->keyBy('period_key');
        $this->assertEqualsCanonicalizing($periods, $markers->keys()->all());
        $this->assertSame(['stats' => 0, 'affinities' => 0, 'rankings' => 0, 'combos' => 0], $markers['2026-07']->rows);
        $this->assertSame($stats['2026-09'], $markers['2026-09']->rows['stats']);
        $this->assertSame($rankings['2026-09'], $markers['2026-09']->rows['rankings']);
        $this->assertSame([$buildIds->first()], $markers->pluck('build_id')->unique()->values()->all());
        $this->assertSame(self::TODAY.' 03:00:00', $markers['2026-07']->completed_at->toDateTimeString());
    }

    public function test_a_rebuild_upserts_the_period_marker(): void
    {
        $fixture = $this->fixture();
        $this->fakeModel();
        $orgId = $fixture->organization->id;

        $this->runJob($fixture->organization, ['2026-09']);
        $first = MenuIntelligenceBuild::withoutGlobalScopes()->where('organization_id', $orgId)->sole();

        $this->travel(1)->hours();
        $this->runJob($fixture->organization, ['2026-09']);
        $second = MenuIntelligenceBuild::withoutGlobalScopes()->where('organization_id', $orgId)->sole();

        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($first->build_id, $second->build_id);
        $this->assertTrue($second->completed_at->greaterThan($first->completed_at));
    }

    public function test_given_period_keys_limit_the_build(): void
    {
        $fixture = $this->fixture();
        $this->fakeModel();
        $orgId = $fixture->organization->id;

        $this->runJob($fixture->organization, ['2026-09']);

        $this->assertSame(['2026-09'], array_keys($this->rowsPerPeriod(ItemPeriodStat::class, $orgId)));
    }

    public function test_a_classifier_failure_still_runs_the_deterministic_steps(): void
    {
        $fixture = $this->fixture();
        Exceptions::fake();
        ComponentClassifier::fake(fn (): array => throw new RuntimeException('model down'));
        $orgId = $fixture->organization->id;

        $job = $this->runJob($fixture->organization, ['2026-09']);

        Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'model down');
        $this->assertSame(['step' => 'classify', 'period' => null, 'rows' => 0], array_diff_key($job->timings()[0], ['ms' => 0]));
        $this->assertGreaterThan(0, ItemProfile::withoutGlobalScopes()->where('organization_id', $orgId)->count());
        $this->assertGreaterThan(0, ItemPeriodStat::withoutGlobalScopes()->where('organization_id', $orgId)->count());
        $this->assertGreaterThan(0, ItemRanking::withoutGlobalScopes()->where('organization_id', $orgId)->count());
    }

    public function test_the_command_with_sync_prints_the_timing_table(): void
    {
        $fixture = $this->fixture();
        $this->fakeModel();

        $exitCode = Artisan::call('menu:build-intelligence', [
            'organization' => $fixture->organization->id,
            '--period' => ['2026-09'],
            '--sync' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertMatchesRegularExpression('/\|\s*step\s*\|\s*period\s*\|\s*rows\s*\|\s*ms\s*\|/', $output);
        foreach (['classify', 'profiles', 'stats', 'affinities', 'rankings', 'combos'] as $step) {
            $this->assertStringContainsString($step, $output);
        }
        $this->assertMatchesRegularExpression('/\|\s*stats\s*\|\s*2026-09\s*\|\s*\d+\s*\|\s*\d+\s*\|/', $output);
        $this->assertStringContainsString('Total:', $output);

        $this->assertGreaterThan(0, ItemPeriodStat::withoutGlobalScopes()->where('organization_id', $fixture->organization->id)->count());
    }

    public function test_the_command_rejects_invalid_periods_before_any_step(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 03:00:00'));
        $organization = Organization::factory()->create();

        foreach (['2026-13', 'september', '2026-9', '2026-11', 'rolling_30d'] as $period) {
            $exitCode = Artisan::call('menu:build-intelligence', ['organization' => $organization->id, '--period' => ['2026-09', $period], '--sync' => true]);

            $this->assertSame(1, $exitCode, $period);
            $this->assertStringContainsString("Invalid period [{$period}]", Artisan::output());
        }
        Queue::assertNothingPushed();
        $this->assertSame(0, MenuIntelligenceBuild::withoutGlobalScopes()->count());
        $this->assertSame(0, ItemProfile::withoutGlobalScopes()->count());

        // The current month (to yesterday) and rolling_90d are valid.
        $this->assertSame(0, Artisan::call('menu:build-intelligence', ['organization' => $organization->id, '--period' => ['2026-10', 'rolling_90d']]));
        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->periodKeys === ['2026-10', 'rolling_90d']);
    }

    public function test_the_command_without_sync_queues_the_job(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();

        $this->assertSame(0, Artisan::call('menu:build-intelligence', ['organization' => $organization->id, '--period' => ['2026-09']]));

        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->organizationId === $organization->id
            && $job->periodKeys === ['2026-09']);
    }

    /**
     * Process one batch of the given documents with the real handlers, for a fresh (Pro, AI-enabled by default) organization.
     *
     * @param  list<array{type: string, version: int, op?: string, data: array<string, mixed>}>  $docs
     */
    private function ingestForNewOrganization(array $docs, bool $aiEnabled = true): Organization
    {
        $org = $aiEnabled ? Organization::factory()->pro()->create() : Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);
        $branch = Branch::factory()->create(['organization_id' => $org->id, 'restaurant_id' => $restaurant->id, 'last_seq' => 0]);

        $batchUuid = (string) Str::uuid();
        $documents = array_map(fn (array $d): array => [
            'type' => $d['type'],
            'uuid' => (string) Str::uuid(),
            'version' => $d['version'],
            'op' => $d['op'] ?? 'upsert',
            'occurred_at' => now()->toIso8601String(),
            'data' => $d['data'],
        ], $docs);
        $uuids = array_column($documents, 'uuid');

        $path = "ingest/{$org->id}/{$branch->id}/2026/10/{$batchUuid}.json";
        Storage::disk('local')->put($path, (string) json_encode([
            'schema_version' => 1,
            'batch_uuid' => $batchUuid,
            'branch_uuid' => $branch->uuid,
            'pos_version' => '2.4.0',
            'sent_at' => now()->toIso8601String(),
            'seq_from' => 1,
            'seq_to' => 100,
            'documents' => $documents,
        ]));
        $batch = IngestBatch::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'batch_uuid' => $batchUuid,
            'schema_version' => 1,
            'pos_version' => '2.4.0',
            'seq_from' => 1,
            'seq_to' => 100,
            'docs_count' => count($documents),
            'accepted_count' => count($documents),
            'rejected_count' => 0,
            'status' => 'received',
            'result' => ['batch_uuid' => $batchUuid, 'accepted' => $uuids, 'rejected' => []],
            'storage_path' => $path,
            'received_at' => now(),
        ]);

        (new ProcessIngestBatch($batch->id, $uuids, $branch->id))->handle(app(DocumentHandlerRegistry::class));
        $this->assertSame('processed', $batch->refresh()->status);

        return $org;
    }

    private function assertBuildQueuedFor(Organization $org): void
    {
        Queue::assertPushed(BuildMenuIntelligence::class, 1);
        Queue::assertPushed(BuildMenuIntelligence::class, fn (BuildMenuIntelligence $job): bool => $job->organizationId === $org->id
            && $job->periodKeys === null
            && $job->classifierCalls === 2
            && $job->delay instanceof \DateTimeInterface
            && $job->delay->getTimestamp() === now()->addMinutes((int) config('menu.intelligence.build_delay_minutes'))->getTimestamp());
    }

    /** Today 2026-11-08 (app timezone), clock frozen. */
    private function ingestClock(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-08 10:00:00'));
    }

    /** @return array{type: string, version: int, data: array<string, mixed>} */
    private static function orderDoc(string $businessDate): array
    {
        return ['type' => 'order', 'version' => 1, 'data' => ['number' => 'ORD-'.$businessDate, 'business_date' => $businessDate, 'type' => 'takeaway', 'status' => 'completed']];
    }

    public function test_an_ingest_batch_with_new_catalog_rows_queues_a_delayed_capped_build(): void
    {
        $this->ingestClock();

        $org = $this->ingestForNewOrganization([
            ['type' => 'product', 'version' => 1, 'data' => ['name' => 'Item 1', 'type' => 'manufactured', 'price' => '10.00', 'cost' => '4.00']],
        ]);

        $this->assertSame(15, (int) config('menu.intelligence.build_delay_minutes'));
        $this->assertBuildQueuedFor($org);
    }

    public function test_a_recipe_batch_queues_a_build(): void
    {
        $this->ingestClock();

        $org = $this->ingestForNewOrganization([
            ['type' => 'recipe', 'version' => 1, 'data' => ['product_uuid' => (string) Str::uuid(), 'components' => [['component_uuid' => (string) Str::uuid(), 'quantity' => '0.2500']]]],
        ]);

        $this->assertBuildQueuedFor($org);
    }

    public function test_a_back_dated_order_batch_queues_a_build(): void
    {
        $this->ingestClock();

        $org = $this->ingestForNewOrganization([self::orderDoc('2026-11-08'), self::orderDoc('2026-11-07')]);

        // No catalog growth, so no role classification; the back-dated sale alone triggers the build.
        Queue::assertNotPushed(ClassifyItemRoles::class);
        $this->assertBuildQueuedFor($org);
    }

    public function test_a_today_only_order_batch_queues_no_build(): void
    {
        $this->ingestClock();

        // Today's sales only enter tonight's build; their day is still recomputed.
        $this->ingestForNewOrganization([self::orderDoc('2026-11-08'), self::orderDoc('2026-11-08')]);

        Queue::assertNotPushed(BuildMenuIntelligence::class);
        Queue::assertPushed(RecomputeBranchDay::class);
    }

    public function test_an_expense_batch_queues_no_build(): void
    {
        $this->ingestClock();

        $this->ingestForNewOrganization([
            ['type' => 'expense', 'version' => 1, 'data' => ['business_date' => '2026-10-20', 'expense_type_uuid' => (string) Str::uuid(), 'amount' => '150.00']],
        ]);

        Queue::assertNotPushed(BuildMenuIntelligence::class);
    }

    public function test_an_organization_without_ai_access_gets_no_build(): void
    {
        $this->ingestClock();

        $this->ingestForNewOrganization([
            ['type' => 'product', 'version' => 1, 'data' => ['name' => 'Item 1', 'type' => 'manufactured', 'price' => '10.00', 'cost' => '4.00']],
            self::orderDoc('2026-11-01'),
        ], aiEnabled: false);

        Queue::assertNotPushed(BuildMenuIntelligence::class);
    }

    public function test_an_ingest_batch_with_nothing_applied_queues_no_build(): void
    {
        Storage::fake('local');
        Queue::fake();

        // Deleting an order the platform never saw is skipped by the handler.
        $this->ingestForNewOrganization([
            ['type' => 'order', 'version' => 1, 'op' => 'delete', 'data' => []],
        ]);

        Queue::assertNotPushed(BuildMenuIntelligence::class);
    }

    public function test_the_job_never_overlaps_for_one_organization(): void
    {
        $job = new BuildMenuIntelligence(7);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('7', $middleware[0]->key);
        $this->assertSame(60, $middleware[0]->releaseAfter);
        $this->assertSame(1200, $middleware[0]->expiresAfter);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        // Unique per mode: a pending capped build never swallows the nightly full one; overlap stays keyed by organization.
        $this->assertSame('7:full', $job->uniqueId());
        $capped = new BuildMenuIntelligence(7, null, classifierCalls: 2);
        $this->assertSame('7:capped', $capped->uniqueId());
        $cappedOverlap = $capped->middleware()[0];
        $this->assertInstanceOf(WithoutOverlapping::class, $cappedOverlap);
        $this->assertSame('7', $cappedOverlap->key);
    }

    public function test_retries_are_bounded_by_time_not_count(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 03:00:00'));
        $job = new BuildMenuIntelligence(7);

        $this->assertSame(1, $job->maxExceptions);
        $this->assertSame(now()->addHours(2)->getTimestamp(), $job->retryUntil()->getTimestamp());
    }

    public function test_the_daily_schedule_is_registered(): void
    {
        $events = collect(Schedule::events())->filter(fn ($e) => $e->description === 'build-menu-intelligence');

        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * *', $events->first()->expression);
    }
}
