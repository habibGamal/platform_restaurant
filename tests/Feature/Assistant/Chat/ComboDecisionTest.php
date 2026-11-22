<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Enums\OrganizationPlan;
use App\Models\Menu\ComboCandidate;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComboDecisionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private Organization $otherOrg;

    private User $owner;

    private User $analyst;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        $this->org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->otherOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->owner = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->owner->assignRole('org_owner');

        $this->analyst = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->analyst->assignRole('org_analyst');
    }

    private function createCombo(Organization $org, string $period, string $signature, string $status = 'candidate', ?string $note = null): ComboCandidate
    {
        return ComboCandidate::query()->create([
            'organization_id' => $org->id,
            'period_key' => $period,
            'template' => 'family_feast',
            'channel' => 'all',
            'hero_key' => 'HERO-1',
            'signature' => $signature,
            'items' => [
                ['key' => 'HERO-1', 'name' => 'صينية ملوك', 'role' => 'main', 'qty' => 1, 'list_price' => 400.0],
                ['key' => 'WATER', 'name' => 'مياه', 'role' => 'drink', 'qty' => 4, 'list_price' => 10.0],
                ['key' => 'SOUP', 'name' => 'شوربة', 'role' => 'side', 'qty' => 1, 'list_price' => 50.0],
            ],
            'list_total' => 490,
            'cost_total' => 200,
            'suggested_price' => 450,
            'margin_pct' => 0.5556,
            'discount_pct' => 0.0816,
            'evidence' => ['type' => 'observed', 'together_orders' => 37, 'lift' => 2.5],
            'checks' => [],
            'overlaps_combo_key' => null,
            'score' => 0.7,
            'status' => $status,
            'owner_note' => $note,
            'build_id' => '01JREVIEW0000000000000001',
        ]);
    }

    public function test_approve_updates_every_period_row(): void
    {
        $sig = sha1('COMBO-ALPHA');

        $this->createCombo($this->org, '2026-09', $sig);
        $this->createCombo($this->org, '2026-10', $sig);
        $this->createCombo($this->org, 'rolling_90d', $sig);

        // Another organization with the same signature
        $this->createCombo($this->otherOrg, '2026-10', $sig);

        $response = $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'approved',
                'note' => 'عرض ممتاز للشتاء',
            ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'approved',
                'updated' => 3,
            ]);

        $mine = ComboCandidate::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->org->id)
            ->where('signature', $sig)
            ->get();

        $this->assertCount(3, $mine);
        $this->assertSame(['approved'], $mine->pluck('status')->unique()->all());
        $this->assertSame(['عرض ممتاز للشتاء'], $mine->pluck('owner_note')->unique()->all());

        // Other organization's row remains candidate with null note
        $otherRow = ComboCandidate::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->otherOrg->id)
            ->where('signature', $sig)
            ->firstOrFail();
        $this->assertSame('candidate', $otherRow->status);
        $this->assertNull($otherRow->owner_note);
    }

    public function test_reject_and_reset_to_candidate_update_database(): void
    {
        $sig = sha1('COMBO-BETA');

        $this->createCombo($this->org, '2026-10', $sig, status: 'approved', note: 'ملاحظة قديمة');

        // Reject with empty note string -> stored as null
        $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'rejected',
                'note' => '   ',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'rejected',
                'updated' => 1,
            ]);

        $combo = ComboCandidate::query()->where('signature', $sig)->firstOrFail();
        $this->assertSame('rejected', $combo->status);
        $this->assertNull($combo->owner_note);

        // Reset to candidate
        $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'candidate',
            ])
            ->assertOk()
            ->assertJson([
                'status' => 'candidate',
                'updated' => 1,
            ]);

        $combo->refresh();
        $this->assertSame('candidate', $combo->status);
    }

    public function test_user_without_ai_manage_gets_forbidden(): void
    {
        $sig = sha1('COMBO-GAMMA');
        $this->createCombo($this->org, '2026-10', $sig);

        $this->actingAs($this->analyst)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'approved',
            ])
            ->assertForbidden();

        $combo = ComboCandidate::query()->where('signature', $sig)->firstOrFail();
        $this->assertSame('candidate', $combo->status);
    }

    public function test_combo_decision_of_another_org_signature_is_forbidden(): void
    {
        $otherSig = sha1('OTHER-COMBO');
        $this->createCombo($this->otherOrg, '2026-10', $otherSig);

        $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $otherSig]), [
                'status' => 'approved',
            ])
            ->assertNotFound();

        $otherRow = ComboCandidate::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $this->otherOrg->id)
            ->where('signature', $otherSig)
            ->firstOrFail();

        $this->assertSame('candidate', $otherRow->status);
    }

    public function test_guest_is_unauthorized(): void
    {
        $sig = sha1('COMBO-DELTA');
        $this->createCombo($this->org, '2026-10', $sig);

        $this->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
            'status' => 'approved',
        ])->assertUnauthorized();
    }

    public function test_validation_rejects_invalid_status_and_oversized_note(): void
    {
        $sig = sha1('COMBO-EPSILON');
        $this->createCombo($this->org, '2026-10', $sig);

        $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'not_a_valid_status',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->actingAs($this->owner)
            ->postJson(route('assistant.combos.decision', ['signature' => $sig]), [
                'status' => 'approved',
                'note' => str_repeat('a', 1001),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['note']);
    }
}
