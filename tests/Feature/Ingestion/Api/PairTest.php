<?php

namespace Tests\Feature\Ingestion\Api;

use App\Domain\Tenancy\PairingCodeService;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\BranchPairingCode;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

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

    public function test_valid_code_pairs_branch_and_token_works_for_ingest(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::PENDING,
            'pos_version' => null,
        ]);

        $code = PairingCodeService::generate($branch);

        $response = $this->postJson(route('api.v1.pair'), [
            'code' => $code,
            'pos_version' => '2.4.0',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'branch_uuid',
                'token',
                'branch_secret',
                'timezone',
            ])
            ->assertJson([
                'branch_uuid' => $branch->uuid,
                'timezone' => $branch->timezone,
            ]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertNotEmpty($response->json('branch_secret'));

        $branch->refresh();
        $this->assertSame(BranchStatus::ACTIVE, $branch->status);
        $this->assertSame('2.4.0', $branch->pos_version);

        // Verify the token works for an authenticated ingest route
        $token = $response->json('token');
        $heartbeatResponse = $this->withToken($token)->postJson(route('api.v1.heartbeat'), [
            'pos_version' => '2.4.0',
        ]);

        $heartbeatResponse->assertStatus(200);
    }

    public function test_expired_or_invalid_or_used_code_returns_422(): void
    {
        // 1. Completely invalid code
        $res1 = $this->postJson(route('api.v1.pair'), [
            'code' => 'BADCODE8',
            'pos_version' => '2.4.0',
        ]);
        $res1->assertStatus(422)
            ->assertJson(['message' => 'رمز الربط غير صالح أو منتهي']);

        // 2. Expired code
        $branch = $this->createBranch();
        $code = PairingCodeService::generate($branch);
        BranchPairingCode::where('branch_id', $branch->id)->update([
            'expires_at' => now()->subMinutes(5),
        ]);

        $res2 = $this->postJson(route('api.v1.pair'), [
            'code' => $code,
            'pos_version' => '2.4.0',
        ]);
        $res2->assertStatus(422)
            ->assertJson(['message' => 'رمز الربط غير صالح أو منتهي']);

        // 3. Already used code
        $code2 = PairingCodeService::generate($branch);
        $this->postJson(route('api.v1.pair'), ['code' => $code2, 'pos_version' => '2.4.0'])
            ->assertStatus(200);

        $res3 = $this->postJson(route('api.v1.pair'), [
            'code' => $code2,
            'pos_version' => '2.4.0',
        ]);
        $res3->assertStatus(422)
            ->assertJson(['message' => 'رمز الربط غير صالح أو منتهي']);
    }

    public function test_rotate_token_issues_new_token_and_invalidates_old(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::ACTIVE,
        ]);
        $oldToken = $branch->issueIngestToken();
        $oldTokenId = (int) explode('|', $oldToken)[0];

        $response = $this->withToken($oldToken)->postJson(route('api.v1.pair.rotate'));

        $response->assertStatus(200)
            ->assertJsonStructure(['token']);

        $newToken = $response->json('token');
        $this->assertNotSame($oldToken, $newToken);

        // Old token is revoked from database
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $oldTokenId,
        ]);

        // New token exists in database
        $newTokenId = (int) explode('|', $newToken)[0];
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $newTokenId,
            'tokenable_id' => $branch->id,
        ]);
    }

    public function test_suspended_branch_cannot_rotate_token(): void
    {
        $branch = $this->createBranch([
            'status' => BranchStatus::SUSPENDED,
        ]);
        $token = $branch->issueIngestToken();

        $this->withToken($token)->postJson(route('api.v1.pair.rotate'))
            ->assertStatus(403);
    }
}
