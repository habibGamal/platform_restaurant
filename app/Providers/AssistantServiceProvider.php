<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assistant\AiAccess;
use App\Domain\Assistant\AssistantService;
use App\Domain\Assistant\Contracts\Assistant;
use App\Domain\Assistant\ConversationGuard;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

final class AssistantServiceProvider extends ServiceProvider
{
    /** Seconds a streamed AI response may stay silent; matches the agents' own #[Timeout]. */
    private const STREAM_READ_TIMEOUT = 300;

    /**
     * Register assistant domain services.
     */
    public function register(): void
    {
        $this->app->singleton(AiAccess::class);
        $this->app->singleton(ConversationGuard::class);
        $this->app->bind(Assistant::class, AssistantService::class);
    }

    /**
     * Bootstrap assistant gates and authorization rules.
     */
    public function boot(): void
    {
        // Streamed AI responses go through Guzzle's stream handler, which drops a response after 60 s
        // without a byte unless read_timeout is set (the request timeout does not cover idle reads).
        // Proxies that buffer the whole completion stay silent that long on big answers. cURL ignores
        // this option, so ordinary requests are unaffected.
        Http::globalOptions(['read_timeout' => self::STREAM_READ_TIMEOUT]);

        Gate::define('use-assistant', function (User $user): bool {
            return $this->app->make(AiAccess::class)->allows($user);
        });
    }
}
