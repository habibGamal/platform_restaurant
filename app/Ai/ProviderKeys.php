<?php

declare(strict_types=1);

namespace App\Ai;

final class ProviderKeys
{
    /**
     * Put an organization's own key into a provider config, including the Bearer header
     * when the provider sends its key that way, so the platform key never rides along.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function withKey(array $config, string $key): array
    {
        $config['key'] = $key;

        if (($config['bearer_auth'] ?? false) === true) {
            /** @var array<string, string> $headers */
            $headers = is_array($config['headers'] ?? null) ? $config['headers'] : [];
            $headers['Authorization'] = 'Bearer '.$key;
            $config['headers'] = $headers;
        }

        return $config;
    }
}
