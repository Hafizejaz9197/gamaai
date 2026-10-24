<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Ai\AnthropicEditPlanner;
use App\Services\Ai\EditPlanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EditPlanner::class, fn () => new AnthropicEditPlanner(
            new Client(apiKey: (string) config('services.anthropic.key')),
            (string) config('gama.ai.model'),
            (string) config('gama.ai.effort'),
            (int) config('gama.ai.max_tokens'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
