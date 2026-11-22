<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Settings;

use App\Ai\Agents\RestaurantAnalyst;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Filament\App\Pages\Assistant;
use App\Filament\App\Pages\Management\AiSettingsPage;
use App\Models\AiModelCertification;
use App\Models\AiSetting;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\AnonymousAgent;
use Laravel\Ai\Exceptions\RateLimitedException;
use Livewire\Livewire;
use Tests\TestCase;

class AiSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private User $analyst;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->org = Organization::factory()->create([
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

        $this->manager = User::factory()->create([
            'organization_id' => $this->org->id,
        ]);
        $this->manager->assignRole('branch_manager');
    }

    public function test_owner_can_access_ai_settings_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->assertSuccessful()
            ->assertSee('إعدادات المساعد الذكي')
            ->assertSee('مزود خدمة الذكاء الاصطناعي');
    }

    public function test_analyst_cannot_access_ai_settings_page(): void
    {
        $this->actingAs($this->analyst);

        Livewire::test(AiSettingsPage::class)
            ->assertForbidden();
    }

    public function test_branch_manager_cannot_access_ai_settings_page(): void
    {
        $this->actingAs($this->manager);

        Livewire::test(AiSettingsPage::class)
            ->assertForbidden();
    }

    public function test_ai_disabled_shows_clear_arabic_notice_instead_of_form(): void
    {
        $disabledOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => false,
        ]);

        $owner = User::factory()->create([
            'organization_id' => $disabledOrg->id,
        ]);
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        Livewire::test(AiSettingsPage::class)
            ->assertSuccessful()
            ->assertSee('خدمة المساعد الذكي غير مفعلة لمؤسستك حالياً')
            ->assertDontSee('حفظ التعديلات');
    }

    public function test_non_pro_plan_shows_clear_arabic_notice_instead_of_form(): void
    {
        $basicOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::BASIC,
            'ai_enabled' => true,
        ]);

        $owner = User::factory()->create([
            'organization_id' => $basicOrg->id,
        ]);
        $owner->assignRole('org_owner');

        $this->actingAs($owner);

        Livewire::test(AiSettingsPage::class)
            ->assertSuccessful()
            ->assertSee('المساعد الذكي متاح فقط في باقة الاحترافي (Pro)')
            ->assertDontSee('حفظ التعديلات');
    }

    public function test_saving_writes_expected_ai_settings_and_agent_reads_configured_values(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'deepseek',
                'model' => 'deepseek-chat',
                'reasoning_level' => 'high',
                'failover' => [
                    [
                        'provider' => 'groq',
                        'model' => 'llama-3.3-70b-versatile',
                    ],
                ],
                'monthly_budget_egp' => 2500,
                'per_user_monthly_egp' => 500,
                'warn_at' => 85,
                'hard_stop' => true,
                'data_sharing_ack' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('تم حفظ إعدادات المساعد الذكي بنجاح');

        $setting = AiSetting::query()->where('organization_id', $this->org->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame('deepseek', $setting->provider);
        $this->assertSame('deepseek-chat', $setting->model);
        $this->assertSame('high', $setting->reasoning_level);
        $this->assertSame('0.85', (string) $setting->warn_at);
        $this->assertTrue($setting->hard_stop);
        $this->assertNotNull($setting->data_sharing_ack_at);
        $this->assertCount(1, $setting->failover ?? []);

        // Assert RestaurantAnalyst reads the exact values
        $agent = new RestaurantAnalyst($this->owner, Channel::Chat);
        $this->assertSame('deepseek-chat', $agent->model());
        $this->assertSame([
            'deepseek' => 'deepseek-chat',
            'groq' => 'llama-3.3-70b-versatile',
        ], $agent->provider());
    }

    public function test_blank_byo_key_submission_keeps_existing_encrypted_key(): void
    {
        $secretKey = 'sk-existing-secret-byo-999999999';

        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => $secretKey,
            'data_sharing_ack_at' => now(),
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'model' => 'claude-opus-5-5',
                'byo_key' => '', // blank submission
                'data_sharing_ack' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = AiSetting::query()->where('organization_id', $this->org->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame('claude-opus-5-5', $setting->model);
        $this->assertSame($secretKey, $setting->byo_key);
    }

    public function test_remove_byo_key_action_clears_the_key(): void
    {
        $secretKey = 'sk-to-be-removed-byo-key';

        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => $secretKey,
            'data_sharing_ack_at' => now(),
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->call('removeByoKey')
            ->assertNotified('تمت إزالة المفتاح الخاص بنجاح');

        $setting = AiSetting::query()->where('organization_id', $this->org->id)->first();
        $this->assertNotNull($setting);
        $this->assertNull($setting->byo_key);
    }

    public function test_byo_key_never_appears_in_the_rendered_html(): void
    {
        $secretKey = 'sk-super-secret-never-appear-in-html';

        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'byo_key' => $secretKey,
            'data_sharing_ack_at' => now(),
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->assertDontSee($secretKey, escape: false);
    }

    public function test_data_sharing_checkbox_is_required_to_save(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'reasoning_level' => 'medium',
                'warn_at' => 80,
                'data_sharing_ack' => false,
            ])
            ->call('save')
            ->assertHasFormErrors(['data_sharing_ack']);

        $this->assertDatabaseMissing('ai_settings', [
            'organization_id' => $this->org->id,
        ]);
    }

    public function test_warn_at_conversion_between_ui_and_database(): void
    {
        $this->actingAs($this->owner);

        // Saving 75 in UI stores 0.75 in DB
        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'reasoning_level' => 'medium',
                'warn_at' => 75,
                'data_sharing_ack' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $setting = AiSetting::query()->where('organization_id', $this->org->id)->first();
        $this->assertNotNull($setting);
        $this->assertSame('0.75', (string) $setting->warn_at);

        // Loading 0.75 from DB mounts 75 in form data
        Livewire::test(AiSettingsPage::class)
            ->assertSet('data.warn_at', 75);
    }

    public function test_failover_maximum_limit_is_three(): void
    {
        $this->actingAs($this->owner);

        // 4 items should fail validation
        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'reasoning_level' => 'medium',
                'warn_at' => 80,
                'data_sharing_ack' => true,
                'failover' => [
                    ['provider' => 'openai', 'model' => 'gpt-4o'],
                    ['provider' => 'gemini', 'model' => 'gemini-1.5-pro'],
                    ['provider' => 'deepseek', 'model' => 'deepseek-chat'],
                    ['provider' => 'mistral', 'model' => 'mistral-large-latest'],
                ],
            ])
            ->call('save')
            ->assertHasFormErrors(['failover']);

        // 3 items should pass validation
        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
                'reasoning_level' => 'medium',
                'warn_at' => 80,
                'data_sharing_ack' => true,
                'failover' => [
                    ['provider' => 'openai', 'model' => 'gpt-4o'],
                    ['provider' => 'gemini', 'model' => 'gemini-1.5-pro'],
                    ['provider' => 'deepseek', 'model' => 'deepseek-chat'],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_test_connection_action_succeeds_with_sdk_faked(): void
    {
        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'data_sharing_ack_at' => now(),
        ]);

        AnonymousAgent::fake(['pong']);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->call('testConnection')
            ->assertNotified('تم الاتصال بنجاح');
    }

    public function test_connection_does_not_log_provider_configuration(): void
    {
        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
            'byo_key' => 'organization-secret',
            'data_sharing_ack_at' => now(),
        ]);

        config(['ai.providers.gemini.key' => 'platform-secret']);
        Log::shouldReceive('info')->never();
        AnonymousAgent::fake(['pong']);
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->call('testConnection')
            ->assertNotified('تم الاتصال بنجاح');
    }

    public function test_test_connection_action_reports_error_category_on_failure(): void
    {
        AiSetting::factory()->create([
            'organization_id' => $this->org->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'data_sharing_ack_at' => now(),
        ]);

        AnonymousAgent::fake([
            fn () => throw new RateLimitedException('Rate limit exceeded', 429),
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->call('testConnection')
            ->assertNotified('فشل اختبار الاتصال');
    }

    public function test_certified_model_hint_display(): void
    {
        AiModelCertification::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'passed' => true,
        ]);

        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->fillForm([
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-5-5',
            ])
            ->assertSee('معتمد وموثق');
    }

    public function test_open_assistant_header_action_points_to_assistant_page(): void
    {
        $this->actingAs($this->owner);

        Livewire::test(AiSettingsPage::class)
            ->assertActionExists('open_assistant', function ($action) {
                return $action->getUrl() === Assistant::getUrl();
            });
    }
}
