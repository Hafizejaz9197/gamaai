<?php

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AnthropicEditPlanner;
use App\Services\Ai\EditPlan;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use App\Services\Ai\OpenAiCompatibleEditPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeEditPlanner;
use Tests\TestCase;

class AiSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->role = User::ROLE_ADMIN;
        $user->save();

        return $user;
    }

    private function gemini(array $overrides = []): array
    {
        return $overrides + [
            'provider' => AiSetting::OPENAI_COMPATIBLE,
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'model' => 'gemini-2.5-flash',
            'api_key' => 'AIzaSecretKey123',
        ];
    }

    public function test_without_settings_claude_comes_from_env(): void
    {
        $this->assertInstanceOf(AnthropicEditPlanner::class, $this->app->make(EditPlanner::class));
        $this->actingAs($this->admin())->get('/admin/ai')->assertOk()->assertSee('Anthropic Claude');
    }

    public function test_saving_a_free_provider_switches_the_planner(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/ai', $this->gemini())->assertRedirect('/admin/ai');

        $this->assertInstanceOf(OpenAiCompatibleEditPlanner::class, $this->app->make(EditPlanner::class));
        $setting = AiSetting::current();
        $this->assertSame('AIzaSecretKey123', $setting->api_key);
        $this->assertStringNotContainsString('AIzaSecretKey123', (string) DB::table('ai_settings')->value('api_key'));
        $this->actingAs($admin)->get('/admin/ai')->assertOk()->assertSee('gemini-2.5-flash')->assertDontSee('AIzaSecretKey123');
    }

    public function test_an_empty_key_keeps_the_saved_one_for_the_same_provider(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/admin/ai', $this->gemini());
        $this->actingAs($admin)->put('/admin/ai', $this->gemini(['model' => 'gemini-2.5-pro', 'api_key' => '']));

        $this->assertSame('gemini-2.5-pro', AiSetting::current()->model);
        $this->assertSame('AIzaSecretKey123', AiSetting::current()->api_key);
    }

    public function test_validation(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/admin/ai', $this->gemini(['api_key' => '']))->assertSessionHasErrors('api_key');
        $this->actingAs($admin)->put('/admin/ai', $this->gemini(['base_url' => 'http://insecure.example.com']))->assertSessionHasErrors('base_url');
        $this->actingAs($admin)->put('/admin/ai', $this->gemini(['provider' => 'other']))->assertSessionHasErrors('provider');
        $this->actingAs($admin)->put('/admin/ai', $this->gemini(['model' => 'bad model']))->assertSessionHasErrors('model');
        $this->assertNull(AiSetting::current());
    }

    public function test_switching_back_to_claude_without_a_key_uses_env(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->put('/admin/ai', $this->gemini());
        $this->actingAs($admin)->put('/admin/ai', ['provider' => AiSetting::ANTHROPIC, 'model' => 'claude-opus-5-5', 'api_key' => ''])->assertRedirect();

        $this->assertNull(AiSetting::current()->api_key);
        $this->assertInstanceOf(AnthropicEditPlanner::class, $this->app->make(EditPlanner::class));
    }

    public function test_test_button_shows_the_answer_or_the_error(): void
    {
        $admin = $this->admin();
        $this->app->instance(EditPlanner::class, new FakeEditPlanner(new EditPlan('Renamed it.', [['op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Hello from Gama AI']], 'm', 100, 20)));
        $this->actingAs($admin)->post('/admin/ai/test')->assertOk()->assertSee('The provider answered.')->assertSee('Renamed it.');

        $this->app->instance(EditPlanner::class, new FakeEditPlanner(new EditPlannerException('The AI service did not accept the API key.')));
        $this->actingAs($admin)->post('/admin/ai/test')->assertOk()->assertSee('The test failed.')->assertSee('did not accept the API key');
    }

    public function test_only_admins(): void
    {
        $this->put('/admin/ai', $this->gemini())->assertRedirect('/admin/login');
        $this->actingAs(User::factory()->create())->put('/admin/ai', $this->gemini())->assertForbidden();
        $this->assertNull(AiSetting::current());
    }
}
