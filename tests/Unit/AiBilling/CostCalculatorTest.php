<?php

declare(strict_types=1);

namespace Tests\Unit\AiBilling;

use App\Domain\AiBilling\CostCalculator;
use App\Models\AiModelPrice;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_it_returns_zero_and_unpriced_when_price_is_null(): void
    {
        $calculator = new CostCalculator;
        $usage = new Usage(inputTokens: 1000, outputTokens: 500);

        $result = $calculator->calculate($usage, null);

        $this->assertFalse($result->priced);
        $this->assertSame(0.0, $result->costUsd);
        $this->assertSame(0.0, $result->costEgp);
    }

    public function test_it_prices_plain_usage_without_cache(): void
    {
        $calculator = new CostCalculator(usdToEgpRate: 50.0);
        $price = new AiModelPrice([
            'input_per_1m' => 2.000000,
            'output_per_1m' => 10.000000,
            'cache_read_per_1m' => 0.200000,
            'cache_write_per_1m' => 2.500000,
        ]);

        // 10,000 input tokens = $0.02
        // 2,000 output tokens = $0.02
        // total USD = $0.04 -> EGP = 2.0000
        $usage = new Usage(inputTokens: 10000, outputTokens: 2000);

        $result = $calculator->calculate($usage, $price);

        $this->assertTrue($result->priced);
        $this->assertSame(0.04, $result->costUsd);
        $this->assertSame(2.0, $result->costEgp);
    }

    public function test_it_prices_uncached_input_cache_read_and_cache_write_separately(): void
    {
        $calculator = new CostCalculator(usdToEgpRate: 50.0);
        $price = new AiModelPrice([
            'input_per_1m' => 4.000000,
            'output_per_1m' => 20.000000,
            'cache_read_per_1m' => 0.200000,
            'cache_write_per_1m' => 5.000000,
        ]);

        // TextUsage total inputTokens is 100,000 (which includes cached tokens)
        // cacheRead = 60,000
        // cacheWrite = 20,000
        // uncached = 100,000 - 60,000 - 20,000 = 20,000
        // output = 5,000
        //
        // Costs:
        // uncached: 20,000 * 4 / 1M = $0.08
        // cacheRead: 60,000 * 0.20 / 1M = $0.012
        // cacheWrite: 20,000 * 5.0 / 1M = $0.10
        // output: 5,000 * 20.0 / 1M = $0.10
        // total USD = 0.08 + 0.012 + 0.10 + 0.10 = $0.292
        // total EGP = 0.292 * 50 = 14.60
        $usage = new TextUsage(
            inputTokens: 100000,
            outputTokens: 5000,
            cacheReadInputTokens: 60000,
            cacheWriteInputTokens: 20000,
            reasoningTokens: 1000
        );

        $result = $calculator->calculate($usage, $price);

        $this->assertTrue($result->priced);
        $this->assertSame(0.292, $result->costUsd);
        $this->assertSame(14.6, $result->costEgp);
    }

    public function test_custom_exchange_rate_is_applied(): void
    {
        $calculator = new CostCalculator;
        $price = new AiModelPrice([
            'input_per_1m' => 1.000000,
            'output_per_1m' => 1.000000,
            'cache_read_per_1m' => 0.0,
            'cache_write_per_1m' => 0.0,
        ]);

        $usage = new Usage(inputTokens: 1000000, outputTokens: 0);

        // $1.00 USD at 48.5 EGP/USD
        $result = $calculator->calculate($usage, $price, usdToEgp: 48.5);

        $this->assertSame(1.0, $result->costUsd);
        $this->assertSame(48.5, $result->costEgp);
    }
}
