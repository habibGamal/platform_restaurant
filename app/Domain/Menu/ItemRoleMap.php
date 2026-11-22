<?php

declare(strict_types=1);

namespace App\Domain\Menu;

final class ItemRoleMap
{
    /**
     * @param  array<string, ItemRole>  $trusted  item key => role, trusted assignments only
     */
    public function __construct(private readonly array $trusted) {}

    /** The item's role, or null when it has no trusted role. */
    public function roleOf(string $itemKey): ?ItemRole
    {
        return $this->trusted[$itemKey] ?? null;
    }

    /** Changes whenever any trusted role changes, for keying results that depend on roles. */
    public function fingerprint(): string
    {
        $roles = array_map(fn (ItemRole $role): string => $role->value, $this->trusted);
        ksort($roles);

        return md5((string) json_encode($roles));
    }

    public function isTrusted(string $itemKey): bool
    {
        return isset($this->trusted[$itemKey]);
    }

    /**
     * @param  list<string>  $itemKeys
     * @return list<string>
     */
    public function untrustedAmong(array $itemKeys): array
    {
        return array_values(array_filter(
            $itemKeys,
            fn (string $key): bool => ! $this->isTrusted($key),
        ));
    }
}
