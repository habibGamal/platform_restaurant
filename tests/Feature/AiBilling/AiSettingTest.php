<?php

declare(strict_types=1);

namespace Tests\Feature\AiBilling;

use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\Organization;
use Database\Seeders\AiModelPriceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_byo_key_is_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $organization = Organization::factory()->create();
        $plainKey = 'sk-ant-api03-secret-test-key-12345';

        $setting = AiSetting::factory()->create([
            'organization_id' => $organization->id,
            'byo_key' => $plainKey,
        ]);

        // Model attribute decrypts back to original plain key
        $fresh = $setting->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame($plainKey, $fresh->byo_key);

        // Raw database column is encrypted and does not contain the plaintext
        $raw = DB::table('ai_settings')->where('id', $setting->id)->value('byo_key');
        $this->assertNotSame($plainKey, $raw);
        $this->assertStringNotContainsString('secret-test-key', (string) $raw);

        // Hidden from toArray()
        $array = $fresh->toArray();
        $this->assertArrayNotHasKey('byo_key', $array);

        // Hidden from jsonSerialize() / json_encode()
        $json = json_encode($fresh);
        $this->assertIsString($json);
        $this->assertStringNotContainsString('byo_key', $json);
        $this->assertStringNotContainsString('secret-test-key', $json);
    }

    public function test_ai_model_price_seeder_seeds_reference_prices(): void
    {
        $this->seed(AiModelPriceSeeder::class);

        $this->assertDatabaseHas('ai_model_prices', [
            'provider' => 'anthropic',
            'model' => 'claude-opus-5-5',
            'input_per_1m' => '4.000000',
            'output_per_1m' => '20.000000',
            'cache_read_per_1m' => '0.200000',
            'cache_write_per_1m' => '5.000000',
            'currency' => 'USD',
            'effective_from' => '2026-10-01',
        ]);

        $this->assertDatabaseHas('ai_model_prices', [
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_per_1m' => '2.000000',
            'output_per_1m' => '10.000000',
            'cache_read_per_1m' => '0.200000',
            'cache_write_per_1m' => '2.500000',
            'currency' => 'USD',
            'effective_from' => '2026-10-01',
        ]);

        $this->assertDatabaseHas('ai_model_prices', [
            'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5',
            'input_per_1m' => '0.100000',
            'output_per_1m' => '0.500000',
            'cache_read_per_1m' => '0.010000',
            'cache_write_per_1m' => '0.125000',
            'currency' => 'USD',
            'effective_from' => '2026-10-01',
        ]);

        $this->assertSame(3, AiModelPrice::query()->count());
    }
}
