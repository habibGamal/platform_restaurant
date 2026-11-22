<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Chat;

use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Channel;
use App\Enums\OrganizationPlan;
use App\Models\AiConversationMeta;
use App\Models\AiMessagePart;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\MessageStatus;
use Laravel\Ai\Models\ConversationMessage;
use Tests\TestCase;

final class ThreadApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eligibleOrg;

    private User $allowedUser;

    private Organization $otherOrg;

    private User $otherUser;

    private AssistantService $assistantService;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();

        $this->eligibleOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->allowedUser = User::factory()->create([
            'organization_id' => $this->eligibleOrg->id,
        ]);
        $this->allowedUser->assignRole('org_owner');

        $this->otherOrg = Organization::factory()->create([
            'plan' => OrganizationPlan::PRO,
            'ai_enabled' => true,
        ]);

        $this->otherUser = User::factory()->create([
            'organization_id' => $this->otherOrg->id,
        ]);
        $this->otherUser->assignRole('org_owner');

        $this->assistantService = app(AssistantService::class);
    }

    public function test_list_threads_scoping_and_ordering(): void
    {
        // Thread 1: regular, unpinned, 10 min ago
        $id1 = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'المحادثة الأولى');
        AiConversationMeta::query()->where('conversation_id', $id1)->update([
            'pinned' => false,
            'last_activity_at' => Carbon::now()->subMinutes(10),
            'archived_at' => null,
        ]);

        // Thread 2: regular, pinned, 20 min ago
        $id2 = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'المحادثة المثبتة');
        AiConversationMeta::query()->where('conversation_id', $id2)->update([
            'pinned' => true,
            'last_activity_at' => Carbon::now()->subMinutes(20),
            'archived_at' => null,
        ]);

        // Thread 3: regular, unpinned, 5 min ago
        $id3 = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'المحادثة الأحدث');
        AiConversationMeta::query()->where('conversation_id', $id3)->update([
            'pinned' => false,
            'last_activity_at' => Carbon::now()->subMinutes(5),
            'archived_at' => null,
        ]);

        // Thread 4: archived, unpinned, 1 min ago
        $id4 = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'المحادثة المؤرشفة');
        AiConversationMeta::query()->where('conversation_id', $id4)->update([
            'pinned' => false,
            'last_activity_at' => Carbon::now()->subMinute(),
            'archived_at' => Carbon::now()->subMinute(),
        ]);

        // WhatsApp thread for allowedUser (should be hidden)
        $idWhatsApp = $this->assistantService->createConversation($this->allowedUser, Channel::WhatsApp, 'واتساب');

        // Other user's chat thread (should be hidden)
        $idOther = $this->assistantService->createConversation($this->otherUser, Channel::Chat, 'محادثة مستخدم آخر');

        $response = $this->actingAs($this->allowedUser)->getJson(route('assistant.threads.index'));

        $response->assertOk();
        $items = $response->json();
        $this->assertIsArray($items);
        $this->assertCount(4, $items);

        // Pinned first: id2
        $this->assertSame($id2, $items[0]['id']);
        $this->assertTrue($items[0]['pinned']);
        $this->assertSame('regular', $items[0]['status']);
        $this->assertSame('المحادثة المثبتة', $items[0]['title']);

        // Most recent next: id4 (1 min ago), id3 (5 min ago), id1 (10 min ago)
        $this->assertSame($id4, $items[1]['id']);
        $this->assertFalse($items[1]['pinned']);
        $this->assertSame('archived', $items[1]['status']);

        $this->assertSame($id3, $items[2]['id']);
        $this->assertFalse($items[2]['pinned']);
        $this->assertSame('regular', $items[2]['status']);

        $this->assertSame($id1, $items[3]['id']);
        $this->assertFalse($items[3]['pinned']);
        $this->assertSame('regular', $items[3]['status']);

        // Hidden threads are not present
        $returnedIds = array_column($items, 'id');
        $this->assertNotContains($idWhatsApp, $returnedIds);
        $this->assertNotContains($idOther, $returnedIds);
    }

    public function test_create_thread(): void
    {
        $response = $this->actingAs($this->allowedUser)->postJson(route('assistant.threads.store'), [
            'title' => 'محادثة خاصة جديدة',
        ]);

        $response->assertStatus(201);
        $id = $response->json('id');
        $this->assertIsString($id);
        $this->assertTrue(Str::isUuid($id));

        $this->assertDatabaseHas('ai_conversations_meta', [
            'conversation_id' => $id,
            'user_id' => $this->allowedUser->id,
            'organization_id' => $this->eligibleOrg->id,
            'channel' => Channel::Chat->value,
            'title' => 'محادثة خاصة جديدة',
        ]);

        $this->assertDatabaseHas('agent_conversations', [
            'id' => $id,
            'title' => 'محادثة خاصة جديدة',
        ]);

        // Without title defaults to 'محادثة جديدة'
        $responseDefault = $this->actingAs($this->allowedUser)->postJson(route('assistant.threads.store'), []);
        $responseDefault->assertStatus(201);
        $idDefault = $responseDefault->json('id');
        $this->assertIsString($idDefault);

        $this->assertDatabaseHas('ai_conversations_meta', [
            'conversation_id' => $idDefault,
            'title' => 'محادثة جديدة',
        ]);
    }

    public function test_show_thread(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'محادثة للاختبار');

        $response = $this->actingAs($this->allowedUser)->getJson(route('assistant.threads.show', $id));

        $response->assertOk();
        $response->assertJson([
            'id' => $id,
            'title' => 'محادثة للاختبار',
            'status' => 'regular',
            'pinned' => false,
        ]);
        $this->assertNotNull($response->json('lastActivityAt'));
    }

    public function test_update_thread_rename_archive_pin(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'الاسم القديم');

        // Rename
        $renameResponse = $this->actingAs($this->allowedUser)->patchJson(route('assistant.threads.update', $id), [
            'title' => 'الاسم الجديد',
        ]);
        $renameResponse->assertOk();
        $renameResponse->assertJsonPath('title', 'الاسم الجديد');

        $this->assertDatabaseHas('ai_conversations_meta', ['conversation_id' => $id, 'title' => 'الاسم الجديد']);
        $this->assertDatabaseHas('agent_conversations', ['id' => $id, 'title' => 'الاسم الجديد']);

        // Archive
        $archiveResponse = $this->actingAs($this->allowedUser)->patchJson(route('assistant.threads.update', $id), [
            'status' => 'archived',
        ]);
        $archiveResponse->assertOk();
        $archiveResponse->assertJsonPath('status', 'archived');
        $this->assertDatabaseMissing('ai_conversations_meta', ['conversation_id' => $id, 'archived_at' => null]);

        // Unarchive
        $unarchiveResponse = $this->actingAs($this->allowedUser)->patchJson(route('assistant.threads.update', $id), [
            'status' => 'regular',
        ]);
        $unarchiveResponse->assertOk();
        $unarchiveResponse->assertJsonPath('status', 'regular');
        $this->assertDatabaseHas('ai_conversations_meta', ['conversation_id' => $id, 'archived_at' => null]);

        // Pin
        $pinResponse = $this->actingAs($this->allowedUser)->patchJson(route('assistant.threads.update', $id), [
            'pinned' => true,
        ]);
        $pinResponse->assertOk();
        $pinResponse->assertJsonPath('pinned', true);
        $this->assertDatabaseHas('ai_conversations_meta', ['conversation_id' => $id, 'pinned' => true]);
    }

    public function test_delete_thread_removes_meta_conversation_messages_and_parts(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'محادثة للحذف');

        $msgId = (string) Str::uuid();
        ConversationMessage::query()->create([
            'id' => $msgId,
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'user',
            'content' => 'رسالة للحذف',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        AiMessagePart::query()->create([
            'message_id' => $msgId,
            'conversation_id' => $id,
            'user_id' => $this->allowedUser->id,
            'position' => 0,
            'kind' => 'worklog',
            'tool_name' => null,
            'tool_call_id' => null,
            'payload' => ['plan' => 'x'],
        ]);

        $response = $this->actingAs($this->allowedUser)->deleteJson(route('assistant.threads.destroy', $id));

        $response->assertNoContent();

        $this->assertDatabaseMissing('ai_conversations_meta', ['conversation_id' => $id]);
        $this->assertDatabaseMissing('agent_conversations', ['id' => $id]);
        $this->assertDatabaseMissing('agent_conversation_messages', ['conversation_id' => $id]);
        $this->assertDatabaseMissing('ai_message_parts', ['conversation_id' => $id]);
    }

    public function test_title_set_from_first_user_message_when_default(): void
    {
        // Start with default title
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, null);

        // Add first user message
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'user',
            'content' => "   ما   هي أصناف البيتزا   الأكثر مبيعاً هذا الأسبوع؟   \n\n",
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $response = $this->actingAs($this->allowedUser)->postJson(route('assistant.threads.title', $id));

        $response->assertOk();
        $response->assertJson([
            'title' => 'ما هي أصناف البيتزا الأكثر مبيعاً هذا الأسبوع؟',
        ]);

        $this->assertDatabaseHas('ai_conversations_meta', [
            'conversation_id' => $id,
            'title' => 'ما هي أصناف البيتزا الأكثر مبيعاً هذا الأسبوع؟',
        ]);
        $this->assertDatabaseHas('agent_conversations', [
            'id' => $id,
            'title' => 'ما هي أصناف البيتزا الأكثر مبيعاً هذا الأسبوع؟',
        ]);

        // Long text exceeds 80 characters -> truncated with '…'
        $idLong = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, null);
        $longText = str_repeat('سؤال طويل جداً ومفصل عن إحصائيات المبيعات والأرباح والتكاليف والعمالة ', 3);

        ConversationMessage::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $idLong,
            'agent' => 'coordinator',
            'role' => 'user',
            'content' => $longText,
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $responseLong = $this->actingAs($this->allowedUser)->postJson(route('assistant.threads.title', $idLong));
        $responseLong->assertOk();
        $titleReturned = $responseLong->json('title');
        $this->assertIsString($titleReturned);
        $this->assertLessThanOrEqual(81, mb_strlen($titleReturned));
        $this->assertStringEndsWith('…', $titleReturned);

        // If title is already custom, it is NOT changed
        $idCustom = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'محادثة مخصصة مسبقاً');
        ConversationMessage::query()->create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $idCustom,
            'agent' => 'coordinator',
            'role' => 'user',
            'content' => 'سؤال جديد لن يغير العنوان',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $responseCustom = $this->actingAs($this->allowedUser)->postJson(route('assistant.threads.title', $idCustom));
        $responseCustom->assertOk();
        $responseCustom->assertJson([
            'title' => 'محادثة مخصصة مسبقاً',
        ]);
    }

    public function test_messages_returns_ui_message_format_with_tool_parts_and_cleaned_text(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'تحليل المبيعات');

        // User message
        $userMsgId = (string) Str::uuid();
        ConversationMessage::query()->create([
            'id' => $userMsgId,
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'user',
            'content' => 'أعطني قائمة الأصناف الأكثر مبيعاً',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
            'created_at' => Carbon::now()->subSeconds(10),
            'updated_at' => Carbon::now()->subSeconds(10),
        ]);

        // Assistant message with plan line in content
        $assistantMsgId = (string) Str::uuid();
        $rawContent = "خطة: استعلام عن المبيعات وترتيب الأصناف\nإجمالي مبيعات البيتزا بلغت 50,000 ج.م.";
        ConversationMessage::query()->create([
            'id' => $assistantMsgId,
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'assistant',
            'content' => $rawContent,
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Tool part position 0
        AiMessagePart::query()->create([
            'message_id' => $assistantMsgId,
            'conversation_id' => $id,
            'user_id' => $this->allowedUser->id,
            'position' => 0,
            'kind' => 'tool',
            'tool_name' => 'rank_items',
            'tool_call_id' => 'call_123',
            'payload' => [
                'toolName' => 'rank_items',
                'toolCallId' => 'call_123',
                'input' => ['limit' => 5],
                'output' => ['items' => ['بيتزا مارجريتا', 'بيتزا باربيكيو']],
            ],
        ]);

        // Worklog part position 1
        AiMessagePart::query()->create([
            'message_id' => $assistantMsgId,
            'conversation_id' => $id,
            'user_id' => $this->allowedUser->id,
            'position' => 1,
            'kind' => 'worklog',
            'tool_name' => null,
            'tool_call_id' => null,
            'payload' => [
                'plan' => 'خطة التحليل',
                'agents' => [
                    ['agent' => 'marketing', 'label' => 'التسويق', 'status' => 'done', 'step' => null, 'findings' => [], 'steps' => 2],
                ],
            ],
        ]);

        $response = $this->actingAs($this->allowedUser)->getJson(route('assistant.threads.messages', $id));

        $response->assertOk();
        $messages = $response->json();
        $this->assertIsArray($messages);
        $this->assertCount(2, $messages);

        // User message
        $this->assertSame($userMsgId, $messages[0]['id']);
        $this->assertSame('user', $messages[0]['role']);
        $this->assertCount(1, $messages[0]['parts']);
        $this->assertSame('text', $messages[0]['parts'][0]['type']);
        $this->assertSame('أعطني قائمة الأصناف الأكثر مبيعاً', $messages[0]['parts'][0]['text']);

        // Assistant message: worklog first, then tool, then cleaned text
        $this->assertSame($assistantMsgId, $messages[1]['id']);
        $this->assertSame('assistant', $messages[1]['role']);
        $this->assertCount(3, $messages[1]['parts']);

        // Part 0: data-worklog
        $this->assertSame('data-worklog', $messages[1]['parts'][0]['type']);
        $this->assertSame('worklog', $messages[1]['parts'][0]['id']);
        $this->assertSame('خطة التحليل', $messages[1]['parts'][0]['data']['plan']);
        $this->assertCount(1, $messages[1]['parts'][0]['data']['steps']);
        $this->assertSame('marketing', $messages[1]['parts'][0]['data']['steps'][0]['agent']);
        $this->assertSame('التسويق', $messages[1]['parts'][0]['data']['steps'][0]['label']);
        $this->assertSame('done', $messages[1]['parts'][0]['data']['steps'][0]['status']);

        // Part 1: tool part in position order
        $this->assertSame('tool-rank_items', $messages[1]['parts'][1]['type']);
        $this->assertSame('output-available', $messages[1]['parts'][1]['state']);
        $this->assertSame('call_123', $messages[1]['parts'][1]['toolCallId']);
        $this->assertSame(['limit' => 5], $messages[1]['parts'][1]['input']);
        $this->assertSame(['items' => ['بيتزا مارجريتا', 'بيتزا باربيكيو']], $messages[1]['parts'][1]['output']);

        // Part 2: cleaned text (no plan lines)
        $this->assertSame('text', $messages[1]['parts'][2]['type']);
        $this->assertSame('إجمالي مبيعات البيتزا بلغت 50,000 ج.م.', $messages[1]['parts'][2]['text']);
        $this->assertStringNotContainsString('خطة:', $messages[1]['parts'][2]['text']);
    }

    public function test_assistant_message_with_printed_tool_call_is_cleaned(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'استعلام');

        $assistantMsgId = (string) Str::uuid();
        // A printed tool call in Anthropic/OpenAI tag style
        $rawContent = '<tool_call>{"name": "rank_items", "arguments": {"limit": 5}}</tool_call>';
        ConversationMessage::query()->create([
            'id' => $assistantMsgId,
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'assistant',
            'content' => $rawContent,
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
        ]);

        $response = $this->actingAs($this->allowedUser)->getJson(route('assistant.threads.messages', $id));

        $response->assertOk();
        $messages = $response->json();
        $this->assertCount(1, $messages);
        $this->assertSame(AssistantService::MALFORMED_REPLY_AR, $messages[0]['parts'][0]['text']);
    }

    public function test_threads_of_another_user_are_forbidden(): void
    {
        $otherId = $this->assistantService->createConversation($this->otherUser, Channel::Chat, 'محادثة شخص آخر');

        // GET show
        $this->actingAs($this->allowedUser)
            ->getJson(route('assistant.threads.show', $otherId))
            ->assertForbidden();

        // PATCH update
        $this->actingAs($this->allowedUser)
            ->patchJson(route('assistant.threads.update', $otherId), ['title' => 'اختراق'])
            ->assertForbidden();

        // DELETE destroy
        $this->actingAs($this->allowedUser)
            ->deleteJson(route('assistant.threads.destroy', $otherId))
            ->assertForbidden();

        // POST title
        $this->actingAs($this->allowedUser)
            ->postJson(route('assistant.threads.title', $otherId))
            ->assertForbidden();

        // GET messages
        $this->actingAs($this->allowedUser)
            ->getJson(route('assistant.threads.messages', $otherId))
            ->assertForbidden();
    }

    public function test_data_worklog_part_has_encoder_shape_with_exact_plan_and_steps_keys(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'تجربة خطة العمل');

        $assistantMsgId = (string) Str::uuid();
        ConversationMessage::query()->create([
            'id' => $assistantMsgId,
            'conversation_id' => $id,
            'agent' => 'coordinator',
            'role' => 'assistant',
            'content' => 'التقرير جاهز.',
            'attachments' => [],
            'steps' => [],
            'usage' => [],
            'meta' => [],
            'status' => MessageStatus::Completed,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // Stored worklog snapshot in Livewire fold format {plan, agents: [...]}
        AiMessagePart::query()->create([
            'message_id' => $assistantMsgId,
            'conversation_id' => $id,
            'user_id' => $this->allowedUser->id,
            'position' => 0,
            'kind' => 'worklog',
            'tool_name' => null,
            'tool_call_id' => null,
            'payload' => [
                'plan' => 'خطة شاملة',
                'agents' => [
                    [
                        'agent' => 'marketing',
                        'label' => 'التسويق',
                        'status' => 'running', // must map to 'done'
                        'step' => 'الخطوة الأولى',
                        'findings' => ['النتيجة النهائية للتسويق'],
                        'steps' => 3,
                    ],
                    [
                        'agent' => 'finance',
                        'label' => 'المالية',
                        'status' => 'failed',
                        'step' => 'تحليل التكاليف',
                        'findings' => [],
                        'steps' => 1,
                    ],
                    [
                        'agent' => 'operations',
                        'label' => 'التشغيل',
                        'status' => 'done',
                        'step' => null,
                        'findings' => [],
                        'steps' => 0,
                    ],
                ],
            ],
        ]);

        $response = $this->actingAs($this->allowedUser)->getJson(route('assistant.threads.messages', $id));

        $response->assertOk();
        $messages = $response->json();
        $this->assertCount(1, $messages);

        $parts = $messages[0]['parts'];
        $worklogPart = $parts[0];
        $this->assertSame('data-worklog', $worklogPart['type']);
        $this->assertSame('worklog', $worklogPart['id']);

        $data = $worklogPart['data'];

        // Assert exact keys: plan and steps (never agents)
        $this->assertSame(['plan', 'steps'], array_keys($data));
        $this->assertSame('خطة شاملة', $data['plan']);

        $steps = $data['steps'];
        $this->assertCount(3, $steps);

        // Step 0: marketing (was running -> done, summary from last finding)
        $this->assertSame(['agent', 'label', 'status', 'summary'], array_keys($steps[0]));
        $this->assertSame('marketing', $steps[0]['agent']);
        $this->assertSame('التسويق', $steps[0]['label']);
        $this->assertSame('done', $steps[0]['status']);
        $this->assertSame('النتيجة النهائية للتسويق', $steps[0]['summary']);

        // Step 1: finance (failed -> failed, summary from step text)
        $this->assertSame(['agent', 'label', 'status', 'summary'], array_keys($steps[1]));
        $this->assertSame('finance', $steps[1]['agent']);
        $this->assertSame('المالية', $steps[1]['label']);
        $this->assertSame('failed', $steps[1]['status']);
        $this->assertSame('تحليل التكاليف', $steps[1]['summary']);

        // Step 2: operations (no findings, no step -> summary omitted)
        $this->assertSame(['agent', 'label', 'status'], array_keys($steps[2]));
        $this->assertSame('operations', $steps[2]['agent']);
        $this->assertSame('التشغيل', $steps[2]['label']);
        $this->assertSame('done', $steps[2]['status']);
        $this->assertArrayNotHasKey('summary', $steps[2]);
    }

    public function test_guest_is_unauthorized(): void
    {
        $id = $this->assistantService->createConversation($this->allowedUser, Channel::Chat, 'محادثة');

        $this->getJson(route('assistant.threads.index'))->assertUnauthorized();
        $this->postJson(route('assistant.threads.store'), [])->assertUnauthorized();
        $this->getJson(route('assistant.threads.show', $id))->assertUnauthorized();
        $this->patchJson(route('assistant.threads.update', $id), [])->assertUnauthorized();
        $this->deleteJson(route('assistant.threads.destroy', $id))->assertUnauthorized();
        $this->postJson(route('assistant.threads.title', $id))->assertUnauthorized();
        $this->getJson(route('assistant.threads.messages', $id))->assertUnauthorized();
    }
}
