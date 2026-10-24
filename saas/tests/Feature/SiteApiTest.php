<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Site;
use App\Services\Usage\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_check_in_records_versions_and_returns_usage(): void
    {
        [$site, $token] = $this->site(budget: 50_000);
        (new UsageMeter)->reserve($site->account, $site, 2_000, 'small_edit');

        $this->withToken($token)
            ->postJson('/api/v1/site/check-in', [
                'plugin_version' => '0.3.0',
                'avada_builder_version' => '3.15.6',
                'avada_verified' => true,
            ])
            ->assertOk()
            ->assertJsonPath('site.plugin_version', '0.3.0')
            ->assertJsonPath('usage.held', 2_000)
            ->assertJsonPath('usage.available', 48_000)
            ->assertJsonMissingPath('site.token_hash');

        $site->refresh();
        $this->assertSame('3.15.6', $site->avada_builder_version);
        $this->assertTrue($site->avada_verified);
        $this->assertNotNull($site->last_seen_at);
    }

    public function test_usage_route(): void
    {
        [, $token] = $this->site(budget: 1_000);

        $this->withToken($token)->getJson('/api/v1/site/usage')
            ->assertOk()
            ->assertJsonPath('budget', 1_000)
            ->assertJsonPath('available', 1_000);
    }

    public function test_rejects_missing_and_wrong_tokens(): void
    {
        $this->site();

        $this->getJson('/api/v1/site/usage')->assertUnauthorized();
        $this->withToken('gsk_wrong')->getJson('/api/v1/site/usage')->assertUnauthorized();
        $this->withToken('not-a-site-token')->getJson('/api/v1/site/usage')->assertUnauthorized();
    }

    public function test_check_in_validates_input(): void
    {
        [, $token] = $this->site();

        $this->withToken($token)->postJson('/api/v1/site/check-in', ['plugin_version' => str_repeat('9', 40)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plugin_version', 'avada_verified']);
    }

    public function test_only_the_token_hash_is_stored(): void
    {
        [$site, $token] = $this->site();

        $this->assertStringStartsWith('gsk_', $token);
        $this->assertSame(hash('sha256', $token), $site->token_hash);
        $this->assertSame(substr($token, 0, 10), $site->token_prefix);
        $this->assertTrue(Site::findByToken($token)->is($site));
    }

    public function test_site_create_command_prints_a_working_token(): void
    {
        $account = Account::factory()->create();

        $this->artisan('gama:site-create', ['account' => $account->id, 'name' => 'Sabahat', 'url' => 'https://example.com'])
            ->expectsOutputToContain('Plugin token')
            ->assertSuccessful();

        $this->assertSame(1, $account->sites()->count());
    }

    /**
     * @return array{0: Site, 1: string}
     */
    private function site(?int $budget = null): array
    {
        $account = Account::factory()->create(['monthly_token_budget' => $budget]);
        $site = new Site(['name' => 'Test', 'url' => 'https://example.com']);
        $site->account()->associate($account);
        $token = $site->issueToken();
        $site->save();

        return [$site, $token];
    }
}
