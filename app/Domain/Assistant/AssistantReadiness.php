<?php

declare(strict_types=1);

namespace App\Domain\Assistant;

use App\Models\AiSetting;
use App\Models\Organization;

final class AssistantReadiness
{
    /**
     * Check whether the assistant is ready for the given organization.
     */
    public function check(Organization $org): AssistantReadinessResult
    {
        $setting = AiSetting::query()->where('organization_id', $org->id)->first();

        if ($setting === null) {
            $defaultProvider = (string) config('ai.default', 'openai');
            $hasKey = $this->hasProviderKey($defaultProvider);

            if (! $hasKey) {
                return new AssistantReadinessResult(
                    ready: false,
                    reason: 'not_configured',
                    provider: $defaultProvider,
                    model: null,
                    usesByoKey: false,
                );
            }

            return new AssistantReadinessResult(
                ready: true,
                reason: null,
                provider: $defaultProvider,
                model: null,
                usesByoKey: false,
            );
        }

        $provider = $setting->provider;
        $model = $setting->model;
        $usesByoKey = ! empty($setting->byo_key);

        $hasKey = $usesByoKey || $this->hasProviderKey($provider);

        if (! $hasKey) {
            return new AssistantReadinessResult(
                ready: false,
                reason: 'missing_key',
                provider: $provider,
                model: $model,
                usesByoKey: $usesByoKey,
            );
        }

        if ($setting->data_sharing_ack_at === null) {
            return new AssistantReadinessResult(
                ready: false,
                reason: 'data_sharing_not_acknowledged',
                provider: $provider,
                model: $model,
                usesByoKey: $usesByoKey,
            );
        }

        return new AssistantReadinessResult(
            ready: true,
            reason: null,
            provider: $provider,
            model: $model,
            usesByoKey: $usesByoKey,
        );
    }

    /**
     * Static convenience helper to check readiness.
     */
    public static function for(Organization $org): AssistantReadinessResult
    {
        return (new self)->check($org);
    }

    /**
     * Determine whether the given provider has an available platform key or is keyless.
     */
    private function hasProviderKey(string $provider): bool
    {
        if ($provider === 'ollama') {
            return true;
        }

        $key = config("ai.providers.{$provider}.key");

        return ! empty($key) && trim((string) $key) !== '';
    }
}
