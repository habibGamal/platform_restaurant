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
        $providerKey = self::baseProviderKey($provider instanceof Lab ? $provider->value : strtolower((string) $provider));

        // Per-channel default: whatsapp low, chat medium; explicit reasoning level overrides default.
        $effort = $reasoningLevel ?? self::defaultForChannel($channel);

        // The "openai" provider may run on the Chat Completions driver, which takes a flat `reasoning_effort`.
        if ($providerKey === 'openai' && config('ai.providers.openai.driver') === 'openai-compatible') {
            return ['reasoning_effort' => $effort];
        }

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
                'thinking_level' => $effort,
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
     * Strip the on-demand BYO naming ("byo_{provider}_{orgId}") back to the configured provider key.
     */
    private static function baseProviderKey(string $providerKey): string
    {
        if (preg_match('/^byo_(.+)_\d+$/', $providerKey, $matches) === 1) {
            return $matches[1];
        }

        return $providerKey;
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
