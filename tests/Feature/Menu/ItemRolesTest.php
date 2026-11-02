<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Domain\Menu\ItemKey;
use App\Domain\Menu\ItemRole;
use App\Domain\Menu\ItemRoles;
use App\Models\ItemRoleAssignment;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemRolesTest extends TestCase
{
    use RefreshDatabase;

    private function assign(int $orgId, string $key, string $role, string $source, float $confidence): void
    {
        ItemRoleAssignment::create([
            'organization_id' => $orgId,
            'item_key' => $key,
            'role' => $role,
            'source' => $source,
            'confidence' => $confidence,
            'classified_at' => now(),
        ]);
    }

    public function test_owner_role_is_trusted_and_never_downgraded(): void
    {
        $org = Organization::factory()->create();
        $this->assign($org->id, 'K1', 'drink', 'auto', 0.95);

        app(ItemRoles::class)->setOwnerRole($org->id, 'K1', ItemRole::Main);

        $map = app(ItemRoles::class)->mapFor($org->id);
        $this->assertSame(ItemRole::Main, $map->roleOf('K1'));
        $this->assertTrue($map->isTrusted('K1'));
        $this->assertSame(1, ItemRoleAssignment::query()->where('organization_id', $org->id)->count());

        $row = ItemRoleAssignment::query()->where('item_key', 'K1')->firstOrFail();
        $this->assertSame('owner', $row->source);
        $this->assertSame('1.00', $row->confidence);
    }

    public function test_auto_role_at_threshold_is_trusted(): void
    {
        $org = Organization::factory()->create();
        $this->assign($org->id, 'K3', 'side', 'auto', 0.70);
        $this->assign($org->id, 'K4', 'dessert', 'signal', 0.90);

        $map = app(ItemRoles::class)->mapFor($org->id);

        $this->assertSame(ItemRole::Side, $map->roleOf('K3'));
        $this->assertSame(ItemRole::Dessert, $map->roleOf('K4'));
        $this->assertSame([], $map->untrustedAmong(['K3', 'K4']));
    }

    public function test_low_confidence_auto_role_is_not_trusted(): void
    {
        $org = Organization::factory()->create();
        $this->assign($org->id, 'K2', 'side', 'auto', 0.55);

        $map = app(ItemRoles::class)->mapFor($org->id);

        $this->assertNull($map->roleOf('K2'));
        $this->assertFalse($map->isTrusted('K2'));
        $this->assertSame(['K2'], $map->untrustedAmong(['K2']));
    }

    public function test_unknown_key_is_untrusted(): void
    {
        $org = Organization::factory()->create();

        $map = app(ItemRoles::class)->mapFor($org->id);

        $this->assertNull($map->roleOf('X'));
        $this->assertSame(['X'], $map->untrustedAmong(['X']));
    }

    public function test_roles_are_isolated_per_organization(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $this->assign($a->id, 'SAME', 'main', 'auto', 0.90);
        $this->assign($b->id, 'SAME', 'drink', 'auto', 0.90);

        $this->assertSame(ItemRole::Main, app(ItemRoles::class)->mapFor($a->id)->roleOf('SAME'));
        $this->assertSame(ItemRole::Drink, app(ItemRoles::class)->mapFor($b->id)->roleOf('SAME'));
    }

    public function test_role_predicates(): void
    {
        $this->assertTrue(ItemRole::Main->isMarketable());
        $this->assertTrue(ItemRole::Combo->isMarketable());
        $this->assertFalse(ItemRole::Drink->isMarketable());
        $this->assertFalse(ItemRole::Addon->isMarketable());
        $this->assertTrue(ItemRole::Drink->isComboCompanion());
        $this->assertFalse(ItemRole::Main->isComboCompanion());
        $this->assertFalse(ItemRole::Raw->isComboCompanion());
    }

    public function test_normalize_category_collapses_whitespace_and_lowercases(): void
    {
        $this->assertSame('hot drinks', ItemKey::normalizeCategory("  Hot \t DRINKS \n"));
        $this->assertSame('COALESCE(product_ref, product_uuid)', ItemKey::SQL);
    }
}
