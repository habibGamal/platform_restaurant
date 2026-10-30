<?php

namespace Tests\Feature\Ingestion;

use App\Domain\Ingestion\Contracts\DocumentHandlerRegistry;
use App\Domain\Ingestion\Support\DirtyDateOutbox;
use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Jobs\ProcessIngestBatch;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\IngestBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class QueueContentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Event::fake([JobFailed::class]);
    }

    public function test_ingest_survives_more_than_three_busy_lock_releases_and_then_processes(): void
    {
        $branch = Branch::factory()->active()->create();
        $batch = $this->batch($branch);
        $job = new ProcessIngestBatch($batch->id, [], $branch->id);
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 90);
        $this->assertTrue($lock->get());
        Queue::connection('database')->pushOn('ingest', $job);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->runOne('ingest');
            $this->assertDatabaseCount('jobs', 1);
            $this->assertSame('received', $batch->fresh()->status);
        }
        Event::assertNotDispatched(JobFailed::class);
        $lock->release();
        $this->runOne('ingest');
        $this->assertSame('processed', $batch->fresh()->status);
        $this->assertDatabaseHas('dim_categories', ['branch_id' => $branch->id, 'name' => 'Recovered']);
    }

    public function test_actual_ingest_exceptions_still_fail_after_three_errors(): void
    {
        $branch = Branch::factory()->create();
        $batch = $this->batch($branch);
        $this->app->bind(DocumentHandlerRegistry::class, function (): never {
            throw new RuntimeException('Registry temporarily unavailable');
        });
        Queue::connection('database')->pushOn('ingest', new ProcessIngestBatch($batch->id, [], $branch->id));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->runOne('ingest');
        }
        Event::assertDispatchedTimes(JobFailed::class, 1);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('received', $batch->fresh()->status);
    }

    public function test_aggregate_waits_for_busy_lock_without_exhausting_retries(): void
    {
        $branch = Branch::factory()->create();
        $date = '2026-10-30';
        $token = DirtyDateOutbox::acquireRecomputeLock($branch->id, $date);
        $aggregator = $this->createMock(BranchDayAggregator::class);
        $aggregator->expects($this->once())->method('recompute')->with($branch->id, $date);
        $this->app->instance(BranchDayAggregator::class, $aggregator);
        Queue::connection('database')->pushOn('aggregate', new RecomputeBranchDay($branch->id, $date));
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->runOne('aggregate');
            $this->assertDatabaseCount('jobs', 1);
        }
        DirtyDateOutbox::releaseRecomputeLock($branch->id, $date, $token);
        $this->runOne('aggregate');
        $this->assertDatabaseCount('jobs', 0);
        Event::assertNotDispatched(JobFailed::class);
    }

    public function test_deleted_branch_aggregate_job_finishes_without_releasing_or_recreating_rows(): void
    {
        $branch = Branch::factory()->create();
        $id = $branch->id;
        DB::table('branches')->where('id', $id)->delete();
        Queue::connection('database')->pushOn('aggregate', new RecomputeBranchDay($id, '2026-10-30'));
        $this->runOne('aggregate');
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseMissing('ingest_recompute_fences', ['branch_id' => $id]);
        Event::assertNotDispatched(JobFailed::class);
    }

    private function runOne(string $queue): void
    {
        DB::table('jobs')->where('queue', $queue)->update(['available_at' => 0]);
        app('queue.worker')->setCache(Cache::store())->runNextJob('database', $queue, new WorkerOptions(sleep: 0, maxTries: 3));
    }

    private function batch(Branch $branch): IngestBatch
    {
        $uuid = (string) Str::uuid();
        $path = 'queue-test/batch.json';
        Storage::disk('local')->put($path, json_encode(['documents' => [[
            'type' => 'category', 'uuid' => $uuid, 'version' => 1, 'op' => 'upsert', 'data' => ['name' => 'Recovered'],
        ]]], JSON_THROW_ON_ERROR));

        return IngestBatch::create([
            'organization_id' => $branch->organization_id, 'branch_id' => $branch->id,
            'batch_uuid' => (string) Str::uuid(), 'schema_version' => 1,
            'status' => 'received', 'storage_path' => $path, 'received_at' => now(),
            'result' => ['accepted' => [$uuid], '_accepted_indices' => [0]],
        ]);
    }
}
