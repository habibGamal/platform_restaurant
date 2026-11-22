<?php

declare(strict_types=1);

namespace App\Ai\Agents\Concerns;

use App\Ai\ProviderKeys;
use App\Ai\ProviderOptions;
use App\Domain\Assistant\Channel;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Ai;
use Laravel\Ai\Enums\Lab;
use Throwable;

trait UsesOrganizationAiSettings
{
    abstract protected function aiOrganizationId(): ?int;

    protected function aiChannel(): ?Channel
    {
        return null;
    }

    /**
     * Resolve provider selection with failover chain from organization ai_settings.
     *
     * @return Lab|array<string, string|null>|string
     */
    public function provider(): Lab|array|string
    {
        $orgId = $this->aiOrganizationId();
        if ($orgId === null) {
            return (string) config('ai.default', 'openai');
        }

        $setting = AiSetting::query()->where('organization_id', $orgId)->first();
        if ($setting === null || empty($setting->provider)) {
            return (string) config('ai.default', 'openai');
        }

        $primaryProvider = $setting->provider;
        $primaryModel = $setting->model;

        // BYO key handling via SDK on-demand provider building if supported
        if (! empty($setting->byo_key)) {
            /** @var array<string, mixed> $baseConfig */
            $baseConfig = config("ai.providers.{$primaryProvider}", ['driver' => $primaryProvider]);
            $onDemandName = "byo_{$primaryProvider}_{$orgId}";

            try {
                $built = Ai::build(array_merge(ProviderKeys::withKey($baseConfig, $setting->byo_key), [
                    'name' => $onDemandName,
                ]));
                $primaryProvider = $built->name();
            } catch (Throwable $e) {
                // Falling back to the platform key means the platform pays for this organization's usage,
                // so make it visible instead of failing over silently.
                Log::warning('BYO AI key could not be applied; using the platform key.', [
                    'organization_id' => $orgId,
                    'provider' => $primaryProvider,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $chain = [$primaryProvider => $primaryModel];

        if (is_array($setting->failover)) {
            foreach ($setting->failover as $item) {
                if (! empty($item['provider'])) {
                    $chain[$item['provider']] = $item['model'];
                }
            }
        }

        return $chain;
    }

    /**
     * Resolve model selection when provider is a string.
     */
    public function model(): ?string
    {
        $orgId = $this->aiOrganizationId();
        if ($orgId !== null) {
            $setting = AiSetting::query()->where('organization_id', $orgId)->first();
            if ($setting !== null && ! empty($setting->model)) {
                return $setting->model;
            }
        }

        return null;
    }

    /**
     * Get provider-specific options for reasoning level and caching.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        $orgId = $this->aiOrganizationId();
        $setting = $orgId !== null ? AiSetting::query()->where('organization_id', $orgId)->first() : null;
        $reasoningLevel = $setting?->reasoning_level;

        return ProviderOptions::for($provider, $reasoningLevel, $this->aiChannel());
    }
}
