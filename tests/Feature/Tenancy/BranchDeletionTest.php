<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Metrics\Contracts\BranchDayAggregator;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranches;
use App\Jobs\DeleteBranchFiles;
use App\Jobs\RecomputeBranchDay;
use App\Models\Branch;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BranchDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    public function test_deleting_a_branch_removes_its_data_and_token_but_preserves_other_branches(): void
    {
        Queue::fake();
        Storage::fake('local');
        $branch = Branch::factory()->active()->create();
        $other = Branch::factory()->active()->create(['organization_id' => $branch->organization_id, 'restaurant_id' => $branch->restaurant_id]);
        $tables = $this->seedData($branch);
        $this->seedData($other);
        $token = $branch->issueIngestToken();
        $other->issueIngestToken();

        $branch->delete();

        foreach ($tables as $table) {
            $this->assertDatabaseMissing($table, ['branch_id' => $branch->id]);
            $this->assertDatabaseHas($table, ['branch_id' => $other->id]);
        }
        $this->assertDatabaseMissing('target_daily', ['target_id' => $branch->id]);
        $this->assertDatabaseHas('target_daily', ['target_id' => $other->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_type' => $branch->getMorphClass(), 'tokenable_id' => $branch->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_type' => $other->getMorphClass(), 'tokenable_id' => $other->id]);
        $this->withToken($token)->postJson('/api/v1/heartbeat', [])->assertUnauthorized();
        $this->assertDatabaseHas('users', ['organization_id' => $branch->organization_id]);
        $this->assertDatabaseHas('organizations', ['id' => $branch->organization_id]);
        $this->assertDatabaseHas('restaurants', ['id' => $branch->restaurant_id]);
        Queue::assertPushed(DeleteBranchFiles::class, fn (DeleteBranchFiles $job): bool => $job->branchId === $branch->id && $job->afterCommit === true);

        (new DeleteBranchFiles($branch->organization_id, $branch->id))->handle();
        Storage::disk('local')->assertMissing("ingest/{$branch->organization_id}/{$branch->id}/2026/10/batch.json");
        Storage::disk('local')->assertExists("ingest/{$other->organization_id}/{$other->id}/2026/10/batch.json");
    }

    public function test_rollback_restores_branch_data_and_does_not_remove_files(): void
    {
        Queue::fake();
        Storage::fake('local');
        $branch = Branch::factory()->create();
        $this->seedData($branch);
        $branch->issueIngestToken();
        DB::beginTransaction();
        $branch->delete();
        DB::rollBack();

        $this->assertDatabaseHas('branches', ['id' => $branch->id]);
        $this->assertDatabaseHas('wh_orders', ['branch_id' => $branch->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $branch->id]);
        // A cleanup delivery can never erase files belonging to a surviving branch.
        (new DeleteBranchFiles($branch->organization_id, $branch->id))->handle();
        Storage::disk('local')->assertExists("ingest/{$branch->organization_id}/{$branch->id}/2026/10/batch.json");
    }

    public function test_operator_can_delete_branches_individually_and_in_bulk(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $operator = User::factory()->create(['organization_id' => null]);
        $operator->assignRole('operator');
        $this->actingAs($operator);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Queue::fake();
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();
        $keep = Branch::factory()->create();
        $tables = $this->seedData($first);
        $this->seedData($second);
        $this->seedData($keep);

        Livewire::test(ListBranches::class)->callTableAction('delete', $first)->assertHasNoTableActionErrors();
        Livewire::test(ListBranches::class)->callTableBulkAction('delete', [$second])->assertHasNoTableBulkActionErrors();
        foreach ($tables as $table) {
            $this->assertDatabaseMissing($table, ['branch_id' => $first->id]);
            $this->assertDatabaseMissing($table, ['branch_id' => $second->id]);
            $this->assertDatabaseHas($table, ['branch_id' => $keep->id]);
        }
    }

    public function test_a_late_aggregate_job_cannot_recreate_deleted_branch_data(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        $branch->delete();
        (new RecomputeBranchDay($branch->id, '2026-10-30'))->handle(app(BranchDayAggregator::class));
        app(BranchDayAggregator::class)->recompute($branch->id, '2026-10-30');
        $this->assertDatabaseMissing('ingest_recompute_fences', ['branch_id' => $branch->id]);
        $this->assertDatabaseMissing('agg_branch_daily', ['branch_id' => $branch->id]);
    }

    public function test_late_warehouse_writes_for_a_deleted_branch_are_rejected_by_the_database(): void
    {
        Queue::fake();
        $branch = Branch::factory()->create();
        $branch->delete();
        $this->expectException(QueryException::class);
        DB::table('dim_categories')->insert(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id, 'uuid' => 'late', 'source_version' => 1, 'name' => 'Late category']);
    }

    public function test_a_stale_branch_cannot_issue_a_new_token_after_deletion(): void
    {
        $branch = Branch::factory()->create();
        $stale = $branch->fresh();
        $branch->delete();
        $this->expectException(ModelNotFoundException::class);
        $stale->issueIngestToken();
    }

    public function test_tenant_bulk_deletion_cannot_remove_another_organizations_branch(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $own = Branch::factory()->create();
        $foreign = Branch::factory()->create();
        $owner = User::factory()->create(['organization_id' => $own->organization_id]);
        $owner->assignRole('org_owner');
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(\App\Filament\App\Resources\BranchResource\Pages\ListBranches::class)
            ->callTableBulkAction('delete', [$own, $foreign]);

        $this->assertDatabaseMissing('branches', ['id' => $own->id]);
        $this->assertDatabaseHas('branches', ['id' => $foreign->id]);
    }

    /** @return list<string> */
    private function seedData(Branch $branch): array
    {
        $base = ['organization_id' => $branch->organization_id, 'branch_id' => $branch->id];
        $parent = ['uuid' => 'document', 'source_version' => 1, 'business_date' => '2026-10-30'];
        $rows = [
            'wh_orders' => $parent + ['type' => 'dine_in', 'status' => 'completed'],
            'wh_order_items' => ['order_uuid' => 'document', 'uuid' => 'item', 'quantity' => 1, 'price' => 10, 'total' => 10],
            'wh_payments' => ['order_uuid' => 'document', 'method' => 'cash', 'amount' => 10],
            'wh_order_returns' => $parent + ['order_uuid' => 'document'],
            'wh_order_return_items' => ['return_uuid' => 'document', 'order_item_uuid' => 'item', 'quantity' => 1, 'refund_amount' => 10],
            'wh_refunds' => ['return_uuid' => 'document', 'method' => 'cash', 'amount' => 10],
            'wh_shifts' => $parent,
            'wh_expenses' => $parent + ['amount' => 10],
            'wh_purchases' => $parent + ['kind' => 'invoice', 'total' => 10],
            'wh_purchase_items' => ['purchase_uuid' => 'document', 'quantity' => 1, 'price' => 10, 'total' => 10],
            'wh_wastes' => $parent + ['total' => 10],
            'wh_waste_items' => ['waste_uuid' => 'document', 'quantity' => 1, 'price' => 10, 'total' => 10],
            'wh_stocktakings' => $parent + ['total' => 10],
            'wh_stocktaking_items' => ['stocktaking_uuid' => 'document', 'stock_quantity' => 1, 'real_quantity' => 1, 'price' => 10, 'total' => 10],
            'wh_inventory_days' => $parent,
            'wh_inventory_day_rows' => ['inventory_day_uuid' => 'document', 'business_date' => '2026-10-30', 'product_uuid' => 'product'],
            'wh_day_summaries' => $parent,
            'dim_products' => ['uuid' => 'product', 'source_version' => 1, 'name' => 'Product'],
            'dim_recipes' => ['product_uuid' => 'product', 'component_uuid' => 'component', 'quantity' => 1, 'source_version' => 1],
            'dim_categories' => ['uuid' => 'category', 'source_version' => 1, 'name' => 'Category'],
            'dim_suppliers' => ['uuid' => 'supplier', 'source_version' => 1, 'name' => 'Supplier'],
            'dim_expense_types' => ['uuid' => 'expense', 'source_version' => 1, 'name' => 'Expense'],
            'dim_staff' => ['uuid' => 'staff', 'source_version' => 1, 'name' => 'Staff'],
            'agg_branch_daily' => ['business_date' => '2026-10-30', 'restaurant_id' => $branch->restaurant_id, 'weekday' => 6],
            'agg_product_daily' => ['business_date' => '2026-10-30', 'product_uuid' => 'product'],
            'agg_shift' => ['business_date' => '2026-10-30', 'shift_uuid' => 'shift', 'weekday' => 6],
            'agg_branch_hourly' => ['business_date' => '2026-10-30', 'hour' => 12, 'weekday' => 6],
            'agg_expense_daily' => ['business_date' => '2026-10-30', 'expense_type_uuid' => 'expense', 'category' => 'other'],
            'agg_purchase_daily' => ['business_date' => '2026-10-30', 'kind' => 'invoice'],
            'targets' => ['id' => $branch->id, 'metric' => 'net_sales', 'period_month' => '2026-10-01', 'value' => 100],
            'ingest_batches' => ['id' => $branch->id, 'batch_uuid' => 'batch', 'schema_version' => 1, 'received_at' => now(), 'storage_path' => "ingest/{$branch->organization_id}/{$branch->id}/2026/10/batch.json"],
            'ingest_rejections' => ['ingest_batch_id' => $branch->id, 'doc_type' => 'order', 'reason' => 'test'],
            'ingest_dirty_dates' => ['ingest_batch_id' => $branch->id, 'business_date' => '2026-10-30'],
            'reconciliation_issues' => ['business_date' => '2026-10-30', 'field' => 'net_total'],
        ];
        foreach ($rows as $table => $data) {
            DB::table($table)->insert($base + $data);
        }
        DB::table('target_daily')->insert(['target_id' => $branch->id, 'date' => '2026-10-30', 'value' => 100]);
        DB::table('branch_commands')->insert(['branch_id' => $branch->id, 'type' => 'resync', 'payload' => '{}']);
        DB::table('ingest_recompute_fences')->insert(['branch_id' => $branch->id, 'business_date' => '2026-10-30']);
        DB::table('branch_pairing_codes')->insert(['branch_id' => $branch->id, 'code_hash' => hash('sha256', (string) $branch->id), 'expires_at' => now()->addMinutes(15)]);
        $user = User::factory()->create(['organization_id' => $branch->organization_id]);
        $branch->users()->attach($user);
        $setId = DB::table('branch_sets')->insertGetId(['organization_id' => $branch->organization_id, 'user_id' => $user->id, 'name' => 'Saved branches']);
        $branch->branchSets()->attach($setId);
        Storage::disk('local')->put("ingest/{$branch->organization_id}/{$branch->id}/2026/10/batch.json", '{}');

        return [...array_keys($rows), 'branch_commands', 'ingest_recompute_fences', 'branch_pairing_codes', 'user_branch_access', 'branch_set_members'];
    }
}
