<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Settings;

use App\Domain\Menu\ItemRole;
use App\Filament\App\Pages\Management\AiSettingsPage;
use App\Filament\App\Pages\Management\ItemRolesPage;
use App\Jobs\ClassifyItemRoles;
use App\Models\Branch;
use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use App\Models\User;
use App\Models\Warehouse\DimCategory;
use App\Models\Warehouse\DimProduct;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ItemRolesPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->org = Organization::factory()->create(['plan' => 'pro', 'ai_enabled' => true]);

        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);
        $this->owner->assignRole('org_owner');

        $branchOne = Branch::factory()->create(['organization_id' => $this->org->id]);
        $branchTwo = Branch::factory()->create(['organization_id' => $this->org->id]);

        $this->product($branchOne, 'ALPHA', 'Alpha Dish', 'Cat One');
        $this->product($branchOne, 'BETA', 'Beta Dish', 'Cat One');
        $this->product($branchOne, 'GAMMA', 'Gamma Dish', 'Cat Two');
        $this->product($branchOne, 'DELTA', 'Delta Dish', 'Cat Two');
        // the same item sold in a second branch must still be one row
        $this->product($branchTwo, 'ALPHA', 'Alpha Dish', 'Cat One');

        $this->assignment($this->org, 'ALPHA', 'main', 'owner', 1.00);
        $this->assignment($this->org, 'BETA', 'side', 'auto', 0.40);
        $this->assignment($this->org, 'GAMMA', 'drink', 'signal', 0.90);
        // DELTA has no role row at all
    }

    private function product(Branch $branch, string $ref, string $name, string $category): void
    {
        $org = $branch->organization_id;
        $branchId = $branch->id;
        $categoryUuid = "cat-{$branchId}-".substr(md5($category), 0, 12);
        DimCategory::query()->firstOrCreate(
            ['branch_id' => $branchId, 'uuid' => $categoryUuid],
            ['organization_id' => $org, 'name' => $category, 'source_version' => 1],
        );

        DimProduct::create([
            'organization_id' => $org,
            'branch_id' => $branchId,
            'uuid' => "prod-{$branchId}-{$ref}",
            'source_version' => 1,
            'name' => $name,
            'product_ref' => $ref,
            'category_uuid' => $categoryUuid,
            'type' => 'manufactured',
        ]);
    }

    private function assignment(Organization $org, string $key, string $role, string $source, float $confidence): void
    {
        ItemRoleAssignment::create([
            'organization_id' => $org->id,
            'item_key' => $key,
            'role' => $role,
            'source' => $source,
            'confidence' => $confidence,
            'classified_at' => now(),
        ]);
    }

    public function test_untrusted_items_come_first_then_confidence_ascending(): void
    {
        $this->actingAs($this->owner);

        $component = Livewire::test(ItemRolesPage::class)
            ->assertSuccessful()
            ->assertSee('مراجعة أدوار الأصناف')
            ->assertSee('Alpha Dish')
            ->assertSee('Cat Two')
            ->assertSee('غير مصنف');

        $keys = $component->instance()->getTableRecords()->keys()->all();

        // DELTA: no role. BETA: auto below the trusted threshold. GAMMA: trusted signal. ALPHA: owner.
        $this->assertSame(['DELTA', 'BETA', 'GAMMA', 'ALPHA'], array_map('strval', $keys));
    }

    public function test_only_the_users_own_organization_items_are_listed(): void
    {
        $other = Organization::factory()->create();
        $this->product(Branch::factory()->create(['organization_id' => $other->id]), 'OUTSIDER', 'Outsider Dish', 'Cat Other');

        $this->actingAs($this->owner);

        Livewire::test(ItemRolesPage::class)
            ->assertDontSee('Outsider Dish');
    }

    public function test_changing_a_role_stores_an_owner_assignment(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ItemRolesPage::class)
            ->call('updateTableColumnState', 'role', 'BETA', ItemRole::Dessert->value);

        $row = ItemRoleAssignment::query()
            ->where('organization_id', $this->org->id)
            ->where('item_key', 'BETA')
            ->firstOrFail();

        $this->assertSame('dessert', $row->role);
        $this->assertSame('owner', $row->source);
        $this->assertSame('1.00', (string) $row->confidence);
    }

    public function test_an_unclassified_item_can_be_given_a_role(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(ItemRolesPage::class)
            ->call('updateTableColumnState', 'role', 'DELTA', ItemRole::Addon->value);

        $this->assertDatabaseHas('item_roles', [
            'organization_id' => $this->org->id,
            'item_key' => 'DELTA',
            'role' => 'addon',
            'source' => 'owner',
        ]);
    }

    public function test_an_invalid_role_or_foreign_item_is_ignored(): void
    {
        $other = Organization::factory()->create();
        $this->product(Branch::factory()->create(['organization_id' => $other->id]), 'OUTSIDER', 'Outsider Dish', 'Cat Other');

        $this->actingAs($this->owner);

        Livewire::test(ItemRolesPage::class)
            ->call('updateTableColumnState', 'role', 'BETA', 'chef')
            ->call('updateTableColumnState', 'role', 'OUTSIDER', ItemRole::Main->value);

        $this->assertDatabaseHas('item_roles', ['organization_id' => $this->org->id, 'item_key' => 'BETA', 'role' => 'side', 'source' => 'auto']);
        $this->assertDatabaseMissing('item_roles', ['item_key' => 'OUTSIDER']);
    }

    public function test_user_without_ai_manage_gets_403(): void
    {
        $analyst = User::factory()->create(['organization_id' => $this->org->id]);
        $analyst->assignRole('org_analyst');
        $this->actingAs($analyst);

        $this->assertFalse(ItemRolesPage::canAccess());

        Livewire::test(ItemRolesPage::class)->assertForbidden();
    }

    public function test_reclassify_action_dispatches_the_classification_job(): void
    {
        Queue::fake();
        $this->actingAs($this->owner);

        Livewire::test(ItemRolesPage::class)
            ->callAction('reclassify')
            ->assertNotified('تم جدولة إعادة التصنيف');

        Queue::assertPushed(
            ClassifyItemRoles::class,
            fn (ClassifyItemRoles $job): bool => $job->organizationId === $this->org->id,
        );
    }

    public function test_ai_settings_page_links_to_the_item_roles_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->assertActionExists('item_roles', fn ($action) => $action->getUrl() === ItemRolesPage::getUrl());
    }
}
