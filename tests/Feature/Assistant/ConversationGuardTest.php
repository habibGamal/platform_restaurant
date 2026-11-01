<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Channel;
use App\Domain\Assistant\ConversationGuard;
use App\Models\AiConversationMeta;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\VerifiesConversationOwnership;
use Tests\TestCase;

class ConversationGuardTest extends TestCase
{
    use RefreshDatabase;

    private ConversationGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        (new RolesAndPermissionsSeeder)->run();
        $this->guard = new ConversationGuard;
    }

    public function test_guard_implements_sdk_verifies_conversation_ownership_contract(): void
    {
        $this->assertInstanceOf(VerifiesConversationOwnership::class, $this->guard);
    }

    public function test_register_creates_conversation_metadata(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $meta = $this->guard->register($conversationId, $user, Channel::Chat, 'تحليل مبيعات المعادي');

        $this->assertInstanceOf(AiConversationMeta::class, $meta);
        $this->assertSame($conversationId, $meta->conversation_id);
        $this->assertSame($org->id, $meta->organization_id);
        $this->assertSame($user->id, $meta->user_id);
        $this->assertSame(Channel::Chat, $meta->channel);
        $this->assertSame('تحليل مبيعات المعادي', $meta->title);
        $this->assertFalse($meta->pinned);
        $this->assertNotNull($meta->last_activity_at);
        $this->assertNull($meta->archived_at);

        $this->assertDatabaseHas('ai_conversations_meta', [
            'conversation_id' => $conversationId,
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'channel' => 'chat',
            'title' => 'تحليل مبيعات المعادي',
        ]);
    }

    public function test_register_fails_for_user_without_organization(): void
    {
        $user = User::factory()->create(['organization_id' => null]);
        $conversationId = (string) Str::uuid7();

        $this->expectException(AuthorizationException::class);
        $this->guard->register($conversationId, $user, Channel::Chat);
    }

    public function test_belongs_to_returns_true_for_valid_user_and_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $user, Channel::Chat);

        $this->assertTrue($this->guard->belongsTo($conversationId, $user));
    }

    public function test_cross_user_access_denied_in_same_organization(): void
    {
        $org = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $org->id]);
        $userB = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $userA, Channel::Chat);

        // userB in same org cannot access userA's conversation
        $this->assertFalse($this->guard->belongsTo($conversationId, $userB));
    }

    public function test_cross_org_access_denied(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userOtherOrg = User::factory()->create(['organization_id' => $orgB->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $userA, Channel::Chat);

        $this->assertFalse($this->guard->belongsTo($conversationId, $userOtherOrg));
    }

    public function test_belongs_to_returns_false_for_user_without_organization(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $user, Channel::Chat);

        $userWithoutOrg = User::factory()->create(['organization_id' => null]);
        $this->assertFalse($this->guard->belongsTo($conversationId, $userWithoutOrg));
    }

    public function test_assert_belongs_to_succeeds_for_owner(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $user, Channel::Chat);

        // Should not throw
        $this->guard->assertBelongsTo($conversationId, $user);
        $this->assertTrue($this->guard->belongsTo($conversationId, $user));
    }

    public function test_assert_belongs_to_throws_for_cross_user(): void
    {
        $org = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $org->id]);
        $userB = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $userA, Channel::Chat);

        $this->expectException(AuthorizationException::class);
        $this->guard->assertBelongsTo($conversationId, $userB);
    }

    public function test_assert_belongs_to_throws_for_cross_org(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userOtherOrg = User::factory()->create(['organization_id' => $orgB->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $userA, Channel::Chat);

        $this->expectException(AuthorizationException::class);
        $this->guard->assertBelongsTo($conversationId, $userOtherOrg);
    }

    public function test_sdk_conversation_belongs_to_participant_verification(): void
    {
        $org = Organization::factory()->create();
        $user = User::factory()->create(['organization_id' => $org->id]);
        $otherUser = User::factory()->create(['organization_id' => $org->id]);
        $conversationId = (string) Str::uuid7();

        $this->guard->register($conversationId, $user, Channel::WhatsApp);

        // Matching participant with User::class
        $this->assertTrue($this->guard->conversationBelongsTo($conversationId, User::class, $user->id));

        // Matching participant with null participantType
        $this->assertTrue($this->guard->conversationBelongsTo($conversationId, null, $user->id));

        // Matching participant with morph alias
        $this->assertTrue($this->guard->conversationBelongsTo($conversationId, $user->getMorphClass(), $user->id));

        // Different user in same org
        $this->assertFalse($this->guard->conversationBelongsTo($conversationId, User::class, $otherUser->id));

        // Unknown participant type
        $this->assertFalse($this->guard->conversationBelongsTo($conversationId, 'Some\\Other\\Class', $user->id));

        // Null participant id
        $this->assertFalse($this->guard->conversationBelongsTo($conversationId, User::class, null));
    }
}
