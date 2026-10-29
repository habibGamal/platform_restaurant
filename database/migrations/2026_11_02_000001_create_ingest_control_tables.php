<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->uuid('batch_uuid');
            $table->unsignedSmallInteger('schema_version');
            $table->string('pos_version', 32)->nullable();
            $table->unsignedBigInteger('seq_from')->nullable();
            $table->unsignedBigInteger('seq_to')->nullable();
            $table->unsignedInteger('docs_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);
            $table->string('status', 16)->default('received'); // received | processing | processed | failed
            $table->json('result')->nullable(); // {accepted:[uuid], rejected:[{uuid,type,reason}]} returned on 202/409
            $table->string('storage_path')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'batch_uuid']);
            $table->index(['status', 'received_at']);
        });

        Schema::create('ingest_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingest_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('doc_type', 32);
            $table->string('doc_uuid', 36)->nullable();
            $table->text('reason');
            $table->string('pos_version', 32)->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('reconciliation_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');
            $table->string('field', 32); // orders_count | net_total | expenses_total | waste_total | ...
            $table->decimal('pos_value', 14, 2)->nullable();
            $table->decimal('platform_value', 14, 2)->nullable();
            $table->string('status', 16)->default('open'); // open | resync_requested | resolved | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();
            $table->unique(['branch_id', 'business_date', 'field']);
        });

        // Commands returned to the POS in the heartbeat response (platform -> POS channel)
        Schema::create('branch_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // resync | rotate_token
            $table->json('payload')->nullable(); // resync: {from, to}
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_commands');
        Schema::dropIfExists('reconciliation_issues');
        Schema::dropIfExists('ingest_rejections');
        Schema::dropIfExists('ingest_batches');
    }
};
