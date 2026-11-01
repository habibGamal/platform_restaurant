<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AiAccess;
use App\Enums\OrganizationPlan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiAccessTest extends TestCase
{
    use RefreshDatabase;

    private AiAccess $aiAccess;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->aiAccess = new AiAccess;
    }

    public function test_denied_when_user_has_no_organization(): void
    {
        $user = User::factory()->create([
            'organization_id' => null,
        ]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse($this->aiAccess->allows($user));
        $this->assertSame(AiAccess::CODE_NO_ORGANIZATION, $this->aiAccess->denialReason($user));
        $this->assertStringContainsString('غير مرتبط بأي مؤسسة', $this->aiAccess->messageAr(AiAccess::CODE_NO_ORGANIZATION));
    }

    public function test_denied_when_user_lacks_ai_view_permission(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);

        $this->assertFalse($this->aiAccess->allows($user));
        $this->assertSame(AiAccess::CODE_NO_PERMISSION, $this->aiAccess->denialReason($user));
        $this->assertStringContainsString('صلاحية استخدام المساعد الذكي', $this->aiAccess->messageAr(AiAccess::CODE_NO_PERMISSION));
    }

    public function test_denied_when_organization_has_ai_disabled(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => false,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse($this->aiAccess->allows($user));
        $this->assertSame(AiAccess::CODE_AI_DISABLED, $this->aiAccess->denialReason($user));
        $this->assertStringContainsString('غير مفعلة لمؤسستك', $this->aiAccess->messageAr(AiAccess::CODE_AI_DISABLED));
    }

    public function test_denied_when_organization_plan_is_basic(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::BASIC,
            'ai_enabled' => true,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);
        $user->givePermissionTo('ai.view');

        $this->assertFalse($this->aiAccess->allows($user));
        $this->assertSame(AiAccess::CODE_PLAN_NOT_ELIGIBLE, $this->aiAccess->denialReason($user));
        $this->assertStringContainsString('باقة الاحترافي', $this->aiAccess->messageAr(AiAccess::CODE_PLAN_NOT_ELIGIBLE));
    }

    public function test_allowed_when_user_has_ai_view_and_org_is_pro_and_ai_enabled(): void
    {
        $org = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $user = User::factory()->create([
            'organization_id' => $org->id,
        ]);
        $user->givePermissionTo('ai.view');

        $this->assertTrue($this->aiAccess->allows($user));
        $this->assertNull($this->aiAccess->denialReason($user));
    }
}
