<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingest_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('ingest_batches', 'claim_token')) {
                $table->string('claim_token', 64)->nullable()->after('status');
            }
            if (! Schema::hasColumn('ingest_batches', 'fencing_token')) {
                $table->unsignedBigInteger('fencing_token')->default(0)->after('claim_token');
            }
            if (! Schema::hasColumn('ingest_batches', 'lease_expires_at')) {
                $table->timestamp('lease_expires_at')->nullable()->after('fencing_token');
            }
            if (! Schema::hasColumn('ingest_batches', 'heartbeat_at')) {
                $table->timestamp('heartbeat_at')->nullable()->after('lease_expires_at');
            }
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('ingest_batches', function (Blueprint $table) {
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn(['claim_token', 'fencing_token', 'lease_expires_at', 'heartbeat_at']);
        });
    }
};
