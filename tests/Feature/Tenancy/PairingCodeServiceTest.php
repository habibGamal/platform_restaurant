<?php

namespace Tests\Feature\Tenancy;

use App\Domain\Tenancy\PairingCodeService;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PairingCodeServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $rest = Restaurant::factory()->create(['organization_id' => $org->id]);
        $this->branch = Branch::factory()->create([
            'organization_id' => $org->id,
            'restaurant_id' => $rest->id,
        ]);
        $this->user = User::factory()->forOrganization($org)->create();
    }

    public function test_format(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $code = PairingCodeService::generate($this->branch, $this->user);

            $this->assertSame(8, strlen($code));
            $this->assertSame($code, strtoupper($code));
            $this->assertDoesNotMatchRegularExpression('/[0O1I]/', $code);
            $this->assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{8}$/', $code);
        }
    }

    public function test_expiry_at_15_minutes(): void
    {
        $code = PairingCodeService::generate($this->branch, $this->user);

        // At 14 minutes: should still be redeemable
        $this->travel(14)->minutes();
        $redeemed = PairingCodeService::redeem($code);
        $this->assertNotNull($redeemed);
        $this->assertSame($this->branch->id, $redeemed->id);

        // Generate a new code and travel 16 minutes: should be expired
        $code2 = PairingCodeService::generate($this->branch, $this->user);
        $this->travel(16)->minutes();

        $this->assertNull(PairingCodeService::redeem($code2));
    }

    public function test_single_use(): void
    {
        $code = PairingCodeService::generate($this->branch, $this->user);

        // First redemption succeeds
        $first = PairingCodeService::redeem($code);
        $this->assertNotNull($first);
        $this->assertSame($this->branch->id, $first->id);

        // Second redemption fails
        $second = PairingCodeService::redeem($code);
        $this->assertNull($second);
    }

    public function test_case_insensitive(): void
    {
        $code = PairingCodeService::generate($this->branch, $this->user);

        $lowerCode = strtolower($code);
        $redeemed = PairingCodeService::redeem($lowerCode);

        $this->assertNotNull($redeemed);
        $this->assertSame($this->branch->id, $redeemed->id);
    }

    public function test_regenerate_invalidates_old(): void
    {
        $code1 = PairingCodeService::generate($this->branch, $this->user);
        $code2 = PairingCodeService::generate($this->branch, $this->user);

        // Old code is invalid
        $this->assertNull(PairingCodeService::redeem($code1));

        // New code can be redeemed
        $redeemed = PairingCodeService::redeem($code2);
        $this->assertNotNull($redeemed);
        $this->assertSame($this->branch->id, $redeemed->id);
    }

    public function test_redeem_uses_transactional_row_lock(): void
    {
        $code = PairingCodeService::generate($this->branch, $this->user);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $redeemed = PairingCodeService::redeem($code);
        $this->assertNotNull($redeemed);
        $this->assertSame($this->branch->id, $redeemed->id);

        $hasLockForUpdate = collect($queries)->contains(function (string $sql): bool {
            return str_contains(strtolower($sql), 'for update');
        });

        $this->assertTrue($hasLockForUpdate, 'PairingCodeService::redeem must execute a transactional row lock (FOR UPDATE).');
    }
}
