<?php

namespace Tests\Feature\Ingestion\Api;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchCommand;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createBranch(array $attributes = []): Branch
    {
        $org = Organization::factory()->create();
        $restaurant = Restaurant::factory()->create(['organization_id' => $org->id]);

        return Branch::factory()->create(array_merge([
            'organization_id' => $org->id,
            'restaurant_id' => $restaurant->id,
        ], $attributes));
    }

    public function test_heartbeat_updates_branch_fields_returns_and_marks_commands_delivered(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::ACTIVE,
            'pos_version' => '1.0.0',
            'pending_count' => 0,
            'failed_count' => 0,
            'oldest_pending_at' => null,
            'last_heartbeat_at' => null,
            'last_seen_at' => null,
        ]);
        $token = $branch->issueIngestToken();

        // Create 2 undelivered commands and 1 already delivered command
        $cmd1 = BranchCommand::create([
            'branch_id' => $branch->id,
            'type' => 'resync',
            'payload' => ['from' => '2026-10-01', 'to' => '2026-10-07'],
            'delivered_at' => null,
        ]);

        $cmd2 = BranchCommand::create([
            'branch_id' => $branch->id,
            'type' => 'rotate_token',
            'payload' => null,
            'delivered_at' => null,
        ]);

        $alreadyDelivered = BranchCommand::create([
            'branch_id' => $branch->id,
            'type' => 'resync',
            'payload' => ['from' => '2026-09-01', 'to' => '2026-09-07'],
            'delivered_at' => now()->subDay(),
        ]);

        $response = $this->withToken($token)->postJson(route('api.v1.heartbeat'), [
            'pos_version' => '2.5.0',
            'pending_count' => 15,
            'oldest_pending_at' => '2026-10-29 18:00:00',
            'failed_count' => 2,
            'last_success_at' => '2026-10-29 17:50:00',
            'server_time' => '2026-10-29 18:30:00',
            'business_date_open' => '2026-10-29',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'min_supported_schema' => 1,
                'commands' => [
                    [
                        'type' => 'resync',
                        'from' => '2026-10-01',
                        'to' => '2026-10-07',
                    ],
                    [
                        'type' => 'rotate_token',
                    ],
                ],
            ]);

        $branch->refresh();
        $this->assertSame('2.5.0', $branch->pos_version);
        $this->assertSame(15, $branch->pending_count);
        $this->assertSame(2, $branch->failed_count);
        $this->assertNotNull($branch->oldest_pending_at);
        $this->assertNotNull($branch->last_heartbeat_at);
        $this->assertNotNull($branch->last_seen_at);

        $cmd1->refresh();
        $cmd2->refresh();
        $this->assertNotNull($cmd1->delivered_at);
        $this->assertNotNull($cmd2->delivered_at);

        // Next heartbeat call returns no undelivered commands
        $secondResponse = $this->withToken($token)->postJson(route('api.v1.heartbeat'), [
            'pos_version' => '2.5.0',
        ]);

        $secondResponse->assertStatus(200)
            ->assertJson([
                'commands' => [],
                'min_supported_schema' => 1,
            ]);
    }

    public function test_heartbeat_requires_authentication(): void
    {
        $this->postJson(route('api.v1.heartbeat'), [
            'pos_version' => '1.0.0',
        ])->assertStatus(401);
    }

    public function test_heartbeat_rejects_suspended_branch(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::SUSPENDED,
        ]);
        $token = $branch->issueIngestToken();

        $this->withToken($token)->postJson(route('api.v1.heartbeat'), [
            'pos_version' => '1.0.0',
        ])->assertStatus(403);
    }

    public function test_heartbeat_validates_pos_version(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::ACTIVE,
        ]);
        $token = $branch->issueIngestToken();

        $this->withToken($token)->postJson(route('api.v1.heartbeat'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pos_version']);
    }
}
