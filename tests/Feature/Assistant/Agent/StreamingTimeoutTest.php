<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant\Agent;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StreamingTimeoutTest extends TestCase
{
    /**
     * Guzzle's stream handler (used for every streamed AI response) drops a response after 60 s
     * without a byte unless read_timeout is set, even when the request timeout is longer.
     * Proxies that buffer the whole completion stay silent for that long on big answers.
     */
    public function test_streamed_requests_wait_as_long_as_the_agent_timeout(): void
    {
        $options = Http::timeout(300)->getOptions();

        $this->assertSame(300, $options['read_timeout'] ?? null);
    }
}
