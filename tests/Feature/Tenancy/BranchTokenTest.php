<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class BranchTokenTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $rest = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $rest->id,
        ]);
    }

    public function test_issue_ingest_token_authenticates_with_sanctum_and_has_ingest_ability(): void
    {
        $token = $this->branch->issueIngestToken();

        $this->assertNotEmpty($token);

        // Verify token in database
        $tokenModel = PersonalAccessToken::findToken($token);
        $this->assertNotNull($tokenModel);
        $this->assertSame('pos', $tokenModel->name);
        $this->assertTrue($tokenModel->can('ingest'));
        $this->assertFalse($tokenModel->can('admin'));
        $this->assertSame($this->branch->id, $tokenModel->tokenable_id);
        $this->assertSame(Branch::class, $tokenModel->tokenable_type);

        // Authenticate via Sanctum HTTP endpoint
        $response = $this->withToken($token)->getJson('/api/user');
        $response->assertOk();
        $response->assertJson([
            'id' => $this->branch->id,
            'name' => $this->branch->name,
        ]);
    }

    public function test_issue_ingest_token_replaces_existing_tokens(): void
    {
        $firstToken = $this->branch->issueIngestToken();
        $secondToken = $this->branch->issueIngestToken();

        $this->assertNull(PersonalAccessToken::findToken($firstToken));
        $this->assertNotNull(PersonalAccessToken::findToken($secondToken));
    }

    public function test_revoke_tokens_works(): void
    {
        $token = $this->branch->issueIngestToken();

        $response = $this->withToken($token)->getJson('/api/user');
        $response->assertOk();

        $this->branch->revokeTokens();
        $this->assertNull(PersonalAccessToken::findToken($token));

        $this->app['auth']->forgetGuards();

        $responseAfterRevoke = $this->withToken($token)->getJson('/api/user');
        $responseAfterRevoke->assertUnauthorized();
    }
}
