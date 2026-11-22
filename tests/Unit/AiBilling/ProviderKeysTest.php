<?php

declare(strict_types=1);

namespace Tests\Unit\AiBilling;

use App\Ai\ProviderKeys;
use PHPUnit\Framework\TestCase;

class ProviderKeysTest extends TestCase
{
    public function test_it_replaces_the_platform_bearer_header_with_the_organization_key(): void
    {
        $config = ProviderKeys::withKey([
            'driver' => 'anthropic',
            'key' => 'platform-key',
            'bearer_auth' => true,
            'headers' => ['Authorization' => 'Bearer platform-key', 'X-Other' => 'kept'],
        ], 'org-key');

        $this->assertSame('org-key', $config['key']);
        $this->assertSame('Bearer org-key', $config['headers']['Authorization']);
        $this->assertSame('kept', $config['headers']['X-Other']);
    }

    public function test_it_adds_no_bearer_header_when_the_provider_does_not_use_one(): void
    {
        $config = ProviderKeys::withKey(['driver' => 'anthropic', 'key' => 'platform-key'], 'org-key');

        $this->assertSame('org-key', $config['key']);
        $this->assertArrayNotHasKey('headers', $config);
    }
}
