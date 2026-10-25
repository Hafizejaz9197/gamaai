<?php

namespace App\Providers;

use Anthropic\Client;
use App\Models\AiSetting;
use App\Services\Ai\AnthropicEditPlanner;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\OpenAiCompatibleEditPlanner;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The provider chosen in the admin dashboard; with none chosen, Claude with the key from .env.
        $this->app->bind(EditPlanner::class, function () {
            $setting = AiSetting::current();
            if ($setting?->provider === AiSetting::OPENAI_COMPATIBLE) {
                return new OpenAiCompatibleEditPlanner((string) $setting->base_url, (string) $setting->api_key, $setting->model, (int) config('gama.ai.compat_max_tokens'));
            }

            return new AnthropicEditPlanner(
                new Client(apiKey: (string) ($setting?->api_key ?: config('services.anthropic.key'))),
                $setting?->model ?: (string) config('gama.ai.model'),
                (string) config('gama.ai.effort'),
                (int) config('gama.ai.max_tokens'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
