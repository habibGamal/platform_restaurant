<?php

namespace App\Jobs;

use App\Models\Branch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class DeleteBranchFiles implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 60;

    public function __construct(public int $organizationId, public int $branchId)
    {
        $this->onQueue('ingest')->afterCommit();
    }

    public function handle(): void
    {
        if (Branch::withoutGlobalScopes()->whereKey($this->branchId)->exists()) {
            return;
        }

        if (! Storage::disk('local')->deleteDirectory("ingest/{$this->organizationId}/{$this->branchId}")) {
            throw new RuntimeException("Could not remove stored batches for branch {$this->branchId}.");
        }
    }
}
