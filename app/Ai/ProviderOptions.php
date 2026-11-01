<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Assistant\Channel;
use Laravel\Ai\Enums\Lab;

final class ProviderOptions
{
    /**
     * Map provider reasoning options based on provider, reasoning level, and channel.
     *
     * @return array<string, mixed>
     */
    public static function for(Lab|string $provider, ?string $reasoningLevel = null, ?Channel $channel = null): array
    {
        $providerKey = $provider instanceof Lab ? $provider->value : strtolower((string) $provider);

        // Per-channel default: whatsapp low, chat medium; explicit reasoning level overrides default.
        $effort = $reasoningLevel ?? self::defaultForChannel($channel);

        return match ($providerKey) {
            'anthropic' => [
                'thinking' => [
                    'type' => 'adaptive',
                ],
                'output_config' => [
                    'effort' => $effort,
                ],
                'cache_control' => [
                    'type' => 'ephemeral',
                ],
            ],
            'openai', 'azure' => [
                'reasoning' => [
                    'effort' => $effort,
                ],
            ],
            'gemini' => [
                'thinking_config' => [
                    'thinking_budget' => match ($effort) {
                        'low' => 1024,
                        'high' => 8192,
                        default => 4096,
                    },
                ],
            ],
            default => [],
        };
    }

    /**
     * Map provider reasoning options (alias for `for`).
     *
     * @return array<string, mixed>
     */
    public static function map(Lab|string $provider, ?string $reasoningLevel = null, ?Channel $channel = null): array
    {
        return self::for($provider, $reasoningLevel, $channel);
    }

    /**
     * Determine default reasoning level for a channel.
     */
    public static function defaultForChannel(?Channel $channel): string
    {
        return match ($channel) {
            Channel::WhatsApp => 'low',
            default => 'medium',
        };
    }
}
