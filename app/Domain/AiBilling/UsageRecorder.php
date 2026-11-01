<?php

declare(strict_types=1);

namespace App\Domain\AiBilling;

use App\Models\AiUsageLedgerEntry;
use App\Models\Organization;
use App\Models\User;
use BackedEnum;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\Usage;

final class UsageRecorder
{
    public function __construct(
        private readonly PriceBook $priceBook = new PriceBook,
        private readonly CostCalculator $costCalculator = new CostCalculator,
    ) {}

    public function record(
        Organization|int $organization,
        ?User $user,
        string|BackedEnum $channel,
        string $provider,
        string $model,
        int $step,
        Usage $usage,
        ?int $latencyMs = null,
        ?string $conversationId = null,
        ?string $messageId = null
    ): AiUsageLedgerEntry {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $userId = $user?->id;
        $channelString = $channel instanceof BackedEnum ? (string) $channel->value : $channel;

        $price = $this->priceBook->priceFor($provider, $model);
        $cost = $this->costCalculator->calculate($usage, $price);

        $cacheReadTokens = 0;
        $cacheWriteTokens = 0;
        $reasoningTokens = 0;

        if ($usage instanceof TextUsage) {
            $cacheReadTokens = $usage->cacheReadInputTokens ?? 0;
            $cacheWriteTokens = $usage->cacheWriteInputTokens ?? 0;
            $reasoningTokens = $usage->reasoningTokens ?? 0;
        }

        return AiUsageLedgerEntry::query()->create([
            'organization_id' => $orgId,
            'user_id' => $userId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'channel' => $channelString,
            'provider' => $provider,
            'model' => $model,
            'step' => $step,
            'input_tokens' => $usage->inputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_write_tokens' => $cacheWriteTokens,
            'output_tokens' => $usage->outputTokens,
            'reasoning_tokens' => $reasoningTokens,
            'cost_usd' => $cost->costUsd,
            'cost_egp' => $cost->costEgp,
            'latency_ms' => $latencyMs,
            'created_at' => now(),
        ]);
    }
}
