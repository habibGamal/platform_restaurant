<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

use App\Models\AiModelPrice;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

final class CostCalculator
{
    public function __construct(
        private ?float $usdToEgpRate = null,
    ) {}

    /**
     * Compute cost for a given SDK Usage object and price record.
     *
     * Note on Laravel AI SDK Usage:
     * In \Laravel\Ai\Responses\Data\TextUsage, $inputTokens represents the TOTAL
     * input tokens, including any tokens read from or written to the prompt cache.
     * To price uncached input, cache reads, and cache writes separately without
     * double-charging, uncached input tokens must be computed as:
     * uncached = input_tokens - cache_read_tokens - cache_write_tokens.
     */
    public function calculate(Usage $usage, ?AiModelPrice $price, ?float $usdToEgp = null): UsageCost
    {
        if ($price === null) {
            return new UsageCost(
                costUsd: 0.0,
                costEgp: 0.0,
                priced: false,
            );
        }

        $cacheReadTokens = 0;
        $cacheWriteTokens = 0;

        if ($usage instanceof TextUsage) {
            $cacheReadTokens = $usage->cacheReadInputTokens ?? 0;
            $cacheWriteTokens = $usage->cacheWriteInputTokens ?? 0;
            $uncachedInputTokens = $usage->uncachedInputTokens();
        } else {
            // Plain Usage object does not report cache breakdown
            $uncachedInputTokens = $usage->inputTokens;
        }

        $uncachedInputTokens = max(0, $uncachedInputTokens);
        $outputTokens = max(0, $usage->outputTokens);

        $inputPrice = (float) $price->input_per_1m;
        $outputPrice = (float) $price->output_per_1m;
        $cacheReadPrice = (float) $price->cache_read_per_1m;
        $cacheWritePrice = (float) $price->cache_write_per_1m;

        $costUsd = (
            ($uncachedInputTokens * $inputPrice)
            + ($cacheReadTokens * $cacheReadPrice)
            + ($cacheWriteTokens * $cacheWritePrice)
            + ($outputTokens * $outputPrice)
        ) / 1_000_000.0;

        $rate = $usdToEgp ?? $this->usdToEgpRate ?? (float) config('ai_billing.usd_to_egp', 50.0);
        $costEgp = $costUsd * $rate;

        return new UsageCost(
            costUsd: round($costUsd, 6),
            costEgp: round($costEgp, 4),
            priced: true,
        );
    }
}
