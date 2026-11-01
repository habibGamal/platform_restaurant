<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Channel;
use App\Models\AiConversationMeta;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class AiConversationMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
    }

    public function test_factory_creates_valid_meta(): void
    {
        $meta = AiConversationMeta::factory()->create([
            'channel' => Channel::Scheduled,
        ]);

        $this->assertInstanceOf(AiConversationMeta::class, $meta);
        $this->assertSame(Channel::Scheduled, $meta->channel);
        $this->assertInstanceOf(Organization::class, $meta->organization);
        $this->assertInstanceOf(User::class, $meta->user);
        $this->assertFalse($meta->pinned);
        $this->assertNull($meta->archived_at);
    }

    public function test_channel_casting(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $meta = AiConversationMeta::create([
            'conversation_id' => (string) Str::uuid7(),
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'whatsapp',
            'title' => 'تقرير واتساب اليومي',
        ]);

        $meta->refresh();
        $this->assertSame(Channel::WhatsApp, $meta->channel);
        $this->assertSame('whatsapp', $meta->channel->value);
    }

    public function test_conversation_relation_with_sdk_conversation(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $sdkConversation = Conversation::create([
            'id' => $conversationId,
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->id,
            'title' => 'تحليل الربحية',
        ]);

        $meta = AiConversationMeta::create([
            'conversation_id' => $conversationId,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => Channel::Chat,
            'title' => 'تحليل الربحية',
        ]);

        $this->assertInstanceOf(Conversation::class, $meta->conversation);
        $this->assertSame($sdkConversation->id, $meta->conversation->id);
    }

    public function test_scopes_pinned_active_and_archived(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);

        $activeMeta = AiConversationMeta::factory()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'pinned' => false,
            'archived_at' => null,
        ]);

        $pinnedMeta = AiConversationMeta::factory()->pinned()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'archived_at' => null,
        ]);

        $archivedMeta = AiConversationMeta::factory()->archived()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
        ]);

        $this->assertCount(1, AiConversationMeta::pinned()->get());
        $this->assertSame($pinnedMeta->id, AiConversationMeta::pinned()->first()?->id);

        $this->assertCount(2, AiConversationMeta::active()->get());
        $this->assertTrue(AiConversationMeta::active()->whereKey($activeMeta->id)->exists());
        $this->assertTrue(AiConversationMeta::active()->whereKey($pinnedMeta->id)->exists());

        $this->assertCount(1, AiConversationMeta::archived()->get());
        $this->assertSame($archivedMeta->id, AiConversationMeta::archived()->first()?->id);
    }
}
