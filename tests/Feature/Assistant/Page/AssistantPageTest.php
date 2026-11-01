<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Page;

use App\Domain\Assistant\AssistantEvent;
use App\Domain\Assistant\Channel;
use App\Domain\Assistant\Contracts\Assistant as AssistantContract;
use App\Enums\OrganizationPlan;
use App\Filament\App\Pages\Assistant as AssistantPage;
use App\Models\AiConversationMeta;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\ConversationMessage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class AssistantPageTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eligibleOrg;

    private User $allowedOwner;

    private User $deniedUser;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        Filament::setCurrentPanel(Filament::getPanel('app'));

        $this->eligibleOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->allowedOwner = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->allowedOwner->assignRole('org_owner');

        $this->deniedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        // deniedUser does not have ai.view or role allowing use-assistant
    }

    public function test_page_is_denied_for_user_failing_the_gate(): void
    {
        $this->actingAs($this->deniedUser);

        $this->assertFalse(AssistantPage::canAccess());
        $this->assertFalse(AssistantPage::shouldRegisterNavigation());

        Livewire::test(AssistantPage::class)->assertForbidden();
        $this->get(AssistantPage::getUrl())->assertForbidden();
    }

    public function test_page_is_denied_when_organization_is_not_ai_enabled_or_basic_plan(): void
    {
        $basicOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::BASIC,
            'ai_enabled' => true,
        ]);
        $userOnBasic = User::factory()->create(['organization_id' => $basicOrg->id]);
        $userOnBasic->givePermissionTo('ai.view');

        $this->actingAs($userOnBasic);

        $this->assertFalse(AssistantPage::canAccess());
        $this->assertFalse(AssistantPage::shouldRegisterNavigation());

        Livewire::test(AssistantPage::class)->assertForbidden();
        $this->get(AssistantPage::getUrl())->assertForbidden();
    }

    public function test_allowed_user_can_access_page_and_navigation_registers(): void
    {
        $this->actingAs($this->allowedOwner);

        $this->assertTrue(AssistantPage::canAccess());
        $this->assertTrue(AssistantPage::shouldRegisterNavigation());

        Livewire::test(AssistantPage::class)->assertSuccessful();
        $this->get(AssistantPage::getUrl())->assertSuccessful();
    }

    public function test_sidebar_shows_only_own_active_conversations_and_pinned_first(): void
    {
        $this->actingAs($this->allowedOwner);

        $colleague = User::factory()->create(['organization_id' => $this->eligibleOrg->id]);
        $otherOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);
        $otherOrgUser = User::factory()->create(['organization_id' => $otherOrg->id]);

        // Own normal conversation (older activity)
        $ownNormal = AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة عادية لمالك الحساب',
            'pinned' => false,
            'last_activity_at' => now()->subHours(2),
        ]);

        // Own pinned conversation (older activity, but pinned should come first)
        $ownPinned = AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة مثبتة ذات أولوية',
            'pinned' => true,
            'last_activity_at' => now()->subHours(5),
        ]);

        // Own archived conversation (should NOT appear)
        AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة قديمة مؤرشفة',
            'pinned' => false,
            'last_activity_at' => now()->subDay(),
            'archived_at' => now(),
        ]);

        // Colleague in same organization (should NOT appear)
        AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $colleague->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة زميل العمل بنفس المنشأة',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        // Another organization's user conversation (should NOT appear)
        AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $otherOrg->id,
            'user_id' => $otherOrgUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة مؤسسة منافسة أخرى',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $testable = Livewire::test(AssistantPage::class);

        $testable->assertSuccessful()
            ->assertSee('محادثة عادية لمالك الحساب')
            ->assertSee('محادثة مثبتة ذات أولوية')
            ->assertDontSee('محادثة قديمة مؤرشفة')
            ->assertDontSee('محادثة زميل العمل بنفس المنشأة')
            ->assertDontSee('محادثة مؤسسة منافسة أخرى');

        $instance = $testable->instance();
        $this->assertInstanceOf(AssistantPage::class, $instance);
        $conversations = $instance->getConversations();
        $this->assertCount(2, $conversations);
        $this->assertEquals($ownPinned->conversation_id, $conversations->first()?->conversation_id);
        $this->assertEquals($ownNormal->conversation_id, $conversations->last()?->conversation_id);
    }

    public function test_sidebar_search_filters_conversations_by_title(): void
    {
        $this->actingAs($this->allowedOwner);

        AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'تقرير أرباح شهر مارس',
            'pinned' => false,
            'last_activity_at' => now()->subMinutes(10),
        ]);

        AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid(),
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'تحليل مبيعات فرع المعادي',
            'pinned' => false,
            'last_activity_at' => now()->subMinutes(5),
        ]);

        Livewire::test(AssistantPage::class)
            ->set('search', 'المعادي')
            ->assertSee('تحليل مبيعات فرع المعادي')
            ->assertDontSee('تقرير أرباح شهر مارس');
    }

    public function test_selecting_another_users_conversation_id_is_refused(): void
    {
        $this->actingAs($this->allowedOwner);

        $otherUser = User::factory()->create(['organization_id' => $this->eligibleOrg->id]);
        $otherConvId = (string) Str::uuid();

        AiConversationMeta::create([
            'conversation_id' => $otherConvId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $otherUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة خاصة بمستخدم آخر',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        Livewire::test(AssistantPage::class)
            ->call('selectConversation', $otherConvId)
            ->assertForbidden();

        // Mounting with another user's conversation id should also abort with 403
        Livewire::test(AssistantPage::class, ['conversation' => $otherConvId])
            ->assertForbidden();
    }

    public function test_setting_selected_conversation_id_from_client_is_rejected_as_locked(): void
    {
        $this->actingAs($this->allowedOwner);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(AssistantPage::class)
            ->set('selectedConversationId', (string) Str::uuid());
    }

    public function test_setting_current_run_id_from_client_is_rejected_as_locked(): void
    {
        $this->actingAs($this->allowedOwner);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(AssistantPage::class)
            ->set('currentRunId', (string) Str::uuid());
    }

    public function test_load_messages_for_another_users_conversation_loads_nothing(): void
    {
        $this->actingAs($this->allowedOwner);

        $otherUser = User::factory()->create(['organization_id' => $this->eligibleOrg->id]);
        $otherConvId = (string) Str::uuid();

        AiConversationMeta::create([
            'conversation_id' => $otherConvId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $otherUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة مستخدم آخر',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        ConversationMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $otherConvId,
            'participant_type' => User::class,
            'participant_id' => $otherUser->id,
            'agent' => 'RestaurantAnalyst',
            'role' => 'user',
            'content' => 'رسالة سرية لا يجب أن تظهر',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => 'completed',
        ]);

        $page = new AssistantPage;
        $page->selectedConversationId = $otherConvId;

        // Execute protected loadMessages on the instance
        Closure::bind(fn () => $page->loadMessages(), $page, AssistantPage::class)();

        $this->assertEmpty($page->messages);
    }

    public function test_get_selected_conversation_is_scoped_to_current_user_and_org(): void
    {
        $this->actingAs($this->allowedOwner);

        $otherUser = User::factory()->create(['organization_id' => $this->eligibleOrg->id]);
        $otherConvId = (string) Str::uuid();

        AiConversationMeta::create([
            'conversation_id' => $otherConvId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $otherUser->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة مستخدم آخر',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $page = new AssistantPage;
        $page->selectedConversationId = $otherConvId;

        $this->assertNull($page->getSelectedConversation());
    }

    public function test_sending_message_with_fake_assistant_records_expected_call_and_renders_streamed_text(): void
    {
        $this->actingAs($this->allowedOwner);

        $convId = (string) Str::uuid();

        AiConversationMeta::create([
            'conversation_id' => $convId,
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة تجريبية',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::textDelta('إجمالي مبيعات فرع المعادي الأسبوع الماضي بلغ '),
            AssistantEvent::textDelta('48,210 ج.م بزيادة 12%.'),
            AssistantEvent::done($convId),
        ]);

        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $runId = (string) Str::uuid();

        Livewire::test(AssistantPage::class)
            ->set('prompt', 'ما هي مبيعات فرع المعادي؟')
            ->call('send', $runId)
            ->assertSee('ما هي مبيعات فرع المعادي؟')
            ->assertSee('إجمالي مبيعات فرع المعادي الأسبوع الماضي بلغ')
            ->assertSee('48,210 ج.م بزيادة 12%.')
            ->assertSet('selectedConversationId', $convId);

        $this->assertCount(1, $fakeAssistant->streamCalls);
        $call = $fakeAssistant->streamCalls[0];
        $this->assertEquals($this->allowedOwner->id, $call['user']->id);
        $this->assertEquals(Channel::Chat, $call['channel']);
        $this->assertNull($call['conversationId']);
        $this->assertEquals('ما هي مبيعات فرع المعادي؟', $call['text']);
        $this->assertEquals($runId, $call['runId']);
    }

    public function test_send_and_stop_reject_invalid_run_ids(): void
    {
        $this->actingAs($this->allowedOwner);

        // Send with invalid runId
        Livewire::test(AssistantPage::class)
            ->set('prompt', 'سؤال اختبار')
            ->call('send', 'not-a-valid-uuid')
            ->assertStatus(400);

        // Stop with invalid runId
        Livewire::test(AssistantPage::class)
            ->call('stop', 'invalid-uuid-format')
            ->assertStatus(400);
    }

    public function test_stop_calls_cancel_with_the_run_id(): void
    {
        $this->actingAs($this->allowedOwner);

        $fakeAssistant = new FakeAssistant;
        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $testRunId = (string) Str::uuid();

        Livewire::test(AssistantPage::class)
            ->call('stop', $testRunId)
            ->assertSet('isStreaming', false);

        $this->assertContains($testRunId, $fakeAssistant->cancelledRunIds);
    }

    public function test_streamed_chunks_and_final_message_escape_xss_payloads(): void
    {
        $this->actingAs($this->allowedOwner);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::textDelta("<script>alert('xss1')</script><img src=x onerror=alert('xss2')>"),
            AssistantEvent::done('conv-safe-xss'),
        ]);

        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $runId = (string) Str::uuid();

        Livewire::test(AssistantPage::class)
            ->set('prompt', 'فحص الأمان ضد ثغرات XSS')
            ->call('send', $runId)
            ->assertDontSee('<script>alert(\'xss1\')</script>', false)
            ->assertDontSee('<img src=x onerror=', false)
            ->assertSee('&lt;script&gt;alert(\'xss1\')&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(\'xss2\')&gt;', false);
    }

    public function test_tool_call_events_show_arabic_status_chip(): void
    {
        $this->actingAs($this->allowedOwner);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::toolCall('get_kpis'),
            AssistantEvent::textDelta('البيانات جاهزة.'),
            AssistantEvent::done('conv-tool-1'),
        ]);

        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $runId = (string) Str::uuid();

        Livewire::test(AssistantPage::class)
            ->set('prompt', 'احسب المؤشرات')
            ->call('send', $runId)
            ->assertSuccessful();

        $call = $fakeAssistant->streamCalls[0];
        $this->assertEquals($runId, $call['runId']);
    }

    public function test_error_event_shows_arabic_message_from_the_event(): void
    {
        $this->actingAs($this->allowedOwner);

        $fakeAssistant = new FakeAssistant([
            AssistantEvent::error('budget_exceeded', 'عذراً، تم تجاوز الميزانية الشهرية المحددة للمؤسسة.', 'conv-error-1'),
        ]);

        $this->app->instance(AssistantContract::class, $fakeAssistant);

        $runId = (string) Str::uuid();

        Livewire::test(AssistantPage::class)
            ->set('prompt', 'احسب المؤشرات')
            ->call('send', $runId)
            ->assertSee('عذراً، تم تجاوز الميزانية الشهرية المحددة للمؤسسة.')
            ->assertSet('selectedConversationId', 'conv-error-1');
    }

    public function test_starter_prompts_match_role(): void
    {
        // Owner prompts
        $this->actingAs($this->allowedOwner);
        $ownerPrompts = (new AssistantPage)->getStarterPrompts();
        $this->assertContains('ليه مبيعات فرع المعادي نزلت الأسبوع ده؟', $ownerPrompts);
        $this->assertContains('إيه أكتر 5 أصناف بتخسر؟', $ownerPrompts);

        // Branch manager prompts
        $manager = User::factory()->create(['organization_id' => $this->eligibleOrg->id]);
        $manager->assignRole('branch_manager');
        $manager->givePermissionTo('ai.view');

        $this->actingAs($manager);
        $managerPrompts = (new AssistantPage)->getStarterPrompts();
        $this->assertContains('ليه مبيعات فرعي نزلت الأسبوع ده؟', $managerPrompts);
        $this->assertContains('إيه أكتر 5 أصناف مبيعاً في الفرع؟', $managerPrompts);
    }

    public function test_markdown_is_rendered_safely_escaping_raw_html(): void
    {
        $component = new AssistantPage;

        $safeMarkdown = $component->renderMarkdown("**مبيعات قوية** <script>alert('xss')</script>");

        $this->assertStringContainsString('<strong>مبيعات قوية</strong>', $safeMarkdown);
        $this->assertStringNotContainsString('<script>', $safeMarkdown);
        $this->assertStringContainsString('&lt;script&gt;', $safeMarkdown);
    }

    public function test_new_conversation_resets_selected_conversation_and_state(): void
    {
        $this->actingAs($this->allowedOwner);

        $meta = AiConversationMeta::create([
            'conversation_id' => 'conv-existing-1',
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'محادثة سابقة',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        ConversationMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $meta->conversation_id,
            'participant_type' => User::class,
            'participant_id' => $this->allowedOwner->id,
            'agent' => 'RestaurantAnalyst',
            'role' => 'assistant',
            'content' => 'مرحبا بك في المحادثة السابقة',
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => 'completed',
        ]);

        Livewire::test(AssistantPage::class)
            ->call('selectConversation', 'conv-existing-1')
            ->assertSet('selectedConversationId', 'conv-existing-1')
            ->assertSee('مرحبا بك في المحادثة السابقة')
            ->call('newConversation')
            ->assertSet('selectedConversationId', null)
            ->assertSet('messages', []);
    }

    public function test_stored_answer_shows_the_data_sources_its_tools_used(): void
    {
        $this->actingAs($this->allowedOwner);

        AiConversationMeta::create([
            'conversation_id' => 'conv-sources-1',
            'organization_id' => $this->eligibleOrg->id,
            'user_id' => $this->allowedOwner->id,
            'channel' => Channel::Chat,
            'title' => 'مصادر الإجابة',
            'pinned' => false,
            'last_activity_at' => now(),
        ]);

        ConversationMessage::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => 'conv-sources-1',
            'participant_type' => User::class,
            'participant_id' => $this->allowedOwner->id,
            'agent' => 'RestaurantAnalyst',
            'role' => 'assistant',
            'content' => 'المبيعات ثابتة',
            'attachments' => '[]',
            'steps' => json_encode([
                ['tool_calls' => [['id' => 't1', 'name' => 'resolve_period', 'arguments' => []], ['id' => 't2', 'name' => 'get_kpis', 'arguments' => []]]],
                ['tool_calls' => [['id' => 't3', 'name' => 'get_kpis', 'arguments' => []]]],
            ]),
            'usage' => '[]',
            'meta' => '[]',
            'status' => 'completed',
        ]);

        $component = Livewire::test(AssistantPage::class)->call('selectConversation', 'conv-sources-1');

        $this->assertSame(['الفترة', 'المؤشرات'], $component->get('messages')[0]['sources']);
        $component->assertSeeHtml('<span class="aa-ticket">الفترة</span>')
            ->assertSeeHtml('<span class="aa-ticket">المؤشرات</span>');
    }

    public function test_markdown_marks_suggestions_and_wraps_tables(): void
    {
        $html = (new AssistantPage)->renderMarkdown("عنوان\n\n**اقتراح:** راجع الورديات\n\n| الفرع | المبيعات |\n|---|---|\n| أ | 10 |");

        $this->assertStringContainsString('<p class="aa-suggest"><strong>اقتراح:</strong>', $html);
        $this->assertStringContainsString('<div class="aa-table"><table>', $html);
    }
}
