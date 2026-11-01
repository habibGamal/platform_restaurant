<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Domain\Tenancy\AccessibleBranches;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Trait providing 5-minute caching for AI tool executions, keyed by:
 * (sha1 of user id + sorted accessible branch ids, tool name, normalized args, org cache version).
 *
 * @property-read User $user
 */
trait CachesToolResults
{
    /**
     * Cache duration in seconds (5 minutes).
     */
    protected const CACHE_TTL_SECONDS = 300;

    /**
     * Execute the callback with caching.
     *
     * @param  array<string, mixed>  $args
     * @param  Closure(): string  $callback
     */
    public function cached(string $toolName, array $args, Closure $callback): string
    {
        $cacheKey = $this->toolCacheKey($toolName, $args);

        /** @var string */
        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, $callback);
    }

    /**
     * Generate the deterministic cache key for this tool call.
     *
     * @param  array<string, mixed>  $args
     */
    public function toolCacheKey(string $toolName, array $args): string
    {
        $branchIds = AccessibleBranches::ids($this->user);
        sort($branchIds);

        $userAccessHash = sha1($this->user->id.':'.implode(',', $branchIds));
        $normalizedArgs = $this->normalizeToolArgs($args);
        $argsHash = md5((string) json_encode($normalizedArgs));

        $organizationId = $this->user->organization_id ?? 0;
        /** @var int|string $orgCacheVersion */
        $orgCacheVersion = Cache::get("metrics:version:{$organizationId}", 0);

        return "ai_tool:{$userAccessHash}:{$toolName}:{$argsHash}:{$orgCacheVersion}";
    }

    /**
     * Recursively sort argument keys for deterministic caching.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function normalizeToolArgs(array $args): array
    {
        ksort($args);

        foreach ($args as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $args[$key] = $this->normalizeToolArgs($value);
            }
        }

        return $args;
    }
}
