<?php

namespace App\Providers;

use App\Ai\Gateway\BufferedChatCompletionsGateway;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Providers\OpenAiCompatibleProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAiProviders();
    }

    /**
     * Let an OpenAI-compatible provider opt out of streamed replies with `'stream' => false`.
     *
     * Some proxies drop tool calls in streamed replies; see BufferedChatCompletionsGateway.
     */
    protected function configureAiProviders(): void
    {
        Ai::extend('openai-compatible', function (Application $app, array $config): OpenAiCompatibleProvider {
            /** @var AiManager $this */
            $provider = $this->createOpenaiCompatibleDriver($config);

            if (($config['stream'] ?? true) === false) {
                $provider->useTextGateway(new BufferedChatCompletionsGateway($app->make(Dispatcher::class)));
            }

            return $provider;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
