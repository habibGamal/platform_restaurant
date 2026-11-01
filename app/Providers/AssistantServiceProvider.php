<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assistant\AiAccess;
use App\Domain\Assistant\ConversationGuard;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class AssistantServiceProvider extends ServiceProvider
{
    /**
     * Register assistant domain services.
     */
    public function register(): void
    {
        $this->app->singleton(AiAccess::class);
        $this->app->singleton(ConversationGuard::class);
    }

    /**
     * Bootstrap assistant gates and authorization rules.
     */
    public function boot(): void
    {
        Gate::define('use-assistant', function (User $user): bool {
            return $this->app->make(AiAccess::class)->allows($user);
        });
    }
}
