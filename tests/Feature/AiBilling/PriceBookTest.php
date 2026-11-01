<?php

declare(strict_types=1);

namespace Tests\Feature\AiBilling;

use App\Domain\AiBilling\PriceBook;
use App\Models\AiModelPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PriceBookTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_looks_up_price_by_effective_date(): void
    {
        $book = new PriceBook;

        AiModelPrice::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_per_1m' => 3.000000,
            'output_per_1m' => 15.000000,
            'effective_from' => '2026-01-01',
        ]);

        AiModelPrice::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'input_per_1m' => 2.000000,
            'output_per_1m' => 10.000000,
            'effective_from' => '2026-06-01',
        ]);

        // Prior to any price
        $this->assertNull(
            $book->priceFor('anthropic', 'claude-sonnet-5-5', Carbon::parse('2025-12-31'))
        );

        // In the first price window
        $janPrice = $book->priceFor('anthropic', 'claude-sonnet-5-5', Carbon::parse('2026-03-15'));
        $this->assertNotNull($janPrice);
        $this->assertEquals('3.000000', $janPrice->input_per_1m);

        // After the revision in June
        $julyPrice = $book->priceFor('anthropic', 'claude-sonnet-5-5', Carbon::parse('2026-07-01'));
        $this->assertNotNull($julyPrice);
        $this->assertEquals('2.000000', $julyPrice->input_per_1m);

        // Default lookup uses current time
        Carbon::setTestNow('2026-08-01');
        $currentPrice = $book->priceFor('anthropic', 'claude-sonnet-5-5');
        $this->assertNotNull($currentPrice);
        $this->assertEquals('2.000000', $currentPrice->input_per_1m);
    }

    public function test_it_returns_null_for_unknown_provider_or_model(): void
    {
        $book = new PriceBook;

        AiModelPrice::factory()->create([
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5-5',
            'effective_from' => '2026-01-01',
        ]);

        $this->assertNull($book->priceFor('openai', 'gpt-5-preview'));
        $this->assertNull($book->priceFor('anthropic', 'claude-unknown'));
    }
}
