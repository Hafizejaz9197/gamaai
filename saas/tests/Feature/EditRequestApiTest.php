<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Site;
use App\Models\UsageEntry;
use App\Services\Ai\EditPlan;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeEditPlanner;
use Tests\TestCase;

class EditRequestApiTest extends TestCase
{
    use RefreshDatabase;

    private const BODY = [
        'page_id' => 11,
        'instruction' => 'Change the title to Welcome',
        'sections' => [['id' => '0', 'type' => 'fusion_builder_container']],
        'elements' => ['fusion_title' => ['element_content' => ['type' => 'textarea']]],
    ];

    public function test_returns_operations_and_settles_usage(): void
    {
        [$site, $token] = $this->site(budget: 100_000);
        $planner = $this->fake(new EditPlan(
            summary: 'Changed the title.',
            operations: [['op' => 'replace_text', 'target' => '0.0.0.0', 'text' => 'Welcome']],
            model: 'claude-opus-5-5',
            inputTokens: 1_000,
            outputTokens: 200,
            cacheWriteTokens: 500,
        ));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)
            ->assertOk()
            ->assertJsonPath('summary', 'Changed the title.')
            ->assertJsonPath('operations.0.op', 'replace_text')
            ->assertJsonPath('usage.tokens', 1_700)
            // 1000 * $4 + 200 * $20 + 500 * $5 per MTok = $0.0105
            ->assertJsonPath('usage.estimated_cost_micros', 10_500)
            ->assertJsonPath('usage.account.used', 1_700)
            ->assertJsonPath('usage.account.held', 0);

        $this->assertSame('Change the title to Welcome', $planner->calls[0][0]);
        $this->assertSame(['reserve', 'settle'], UsageEntry::orderBy('id')->pluck('kind')->all());
        $this->assertSame($site->id, UsageEntry::first()->site_id);
    }

    public function test_refuses_when_the_budget_is_spent(): void
    {
        [, $token] = $this->site(budget: 500);
        $planner = $this->fake(new EditPlan('x', [], 'claude-opus-5-5', 1, 1), estimate: 1_000);

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)
            ->assertStatus(402)
            ->assertJsonPath('usage.available', 500);

        $this->assertSame([], $planner->calls);
        $this->assertSame(0, UsageEntry::count());
    }

    public function test_failed_call_without_charge_releases_the_reservation(): void
    {
        [, $token] = $this->site(budget: 10_000);
        $this->fake(new EditPlannerException('The AI service could not be reached.'));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)
            ->assertStatus(502)
            ->assertJsonPath('message', 'The AI service could not be reached.');

        $this->assertSame(['reserve', 'release'], UsageEntry::orderBy('id')->pluck('kind')->all());
    }

    public function test_rate_limit_returns_429_with_the_wait_and_releases_the_reservation(): void
    {
        [, $token] = $this->site(budget: 10_000);
        $this->fake(new EditPlannerException('The AI service is busy. Try again in 20 seconds.', rateLimited: true, retryAfter: 20, attempts: 2));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)
            ->assertStatus(429)
            ->assertHeader('Retry-After', '20')
            ->assertJsonPath('retry_after', 20)
            ->assertJsonPath('message', 'The AI service is busy. Try again in 20 seconds.');

        $release = UsageEntry::where('kind', 'release')->sole();
        $this->assertSame(['status' => 'rate_limited', 'attempts' => 2, 'retry_after' => 20], array_intersect_key($release->meta, array_flip(['status', 'attempts', 'retry_after'])));
    }

    public function test_records_context_size_estimate_and_output_cap_with_the_usage(): void
    {
        [, $token] = $this->site(budget: 100_000);
        $planner = $this->fake(new EditPlan('ok', [], 'test-model', 300, 40, attempts: 2), estimate: 5_000);

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY + [
            'scope' => 'Only the 2nd container of 3 is shown.',
            'context_stats' => ['settings_count' => 2, 'element_types' => 1, 'focus' => '1', 'anything_else' => 'dropped'],
        ])
            ->assertOk()
            ->assertJsonPath('usage.estimated_tokens', 5_000)
            ->assertJsonPath('usage.max_output_tokens', 4096)
            ->assertJsonPath('usage.input_tokens', 300)
            ->assertJsonPath('usage.output_tokens', 40);

        $this->assertSame('Only the 2nd container of 3 is shown.', $planner->calls[0][3]);
        $reserve = UsageEntry::where('kind', 'reserve')->sole()->meta;
        $this->assertSame(5_000, $reserve['estimated_tokens']);
        $this->assertSame(4096, $reserve['max_output_tokens']);
        $this->assertTrue($reserve['scope']);
        $this->assertSame(2, $reserve['settings_count']);
        $this->assertArrayNotHasKey('anything_else', $reserve);
        $this->assertGreaterThan(0, $reserve['context_bytes']);
        $settle = UsageEntry::where('kind', 'settle')->sole()->meta;
        $this->assertSame('ok', $settle['status']);
        $this->assertSame(2, $settle['attempts']);
        $this->assertArrayHasKey('duration_ms', $settle);
    }

    public function test_media_library_images_reach_the_planner(): void
    {
        [, $token] = $this->site(budget: 100_000);
        $planner = $this->fake(new EditPlan('ok', [], 'test-model', 300, 40));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY + [
            'media' => [['id' => 7, 'url' => 'https://example.com/a.jpg', 'title' => 'Team', 'extra' => 'dropped']],
        ])->assertOk();

        $this->assertSame([['id' => 7, 'url' => 'https://example.com/a.jpg', 'title' => 'Team']], $planner->calls[0][4]);
        $this->assertSame(1, UsageEntry::where('kind', 'reserve')->sole()->meta['media_count']);
    }

    public function test_an_attached_image_reaches_the_planner_but_not_the_ledger(): void
    {
        [, $token] = $this->site(budget: 100_000);
        $planner = $this->fake(new EditPlan('ok', [], 'test-model', 300, 40));
        $image = 'data:image/jpeg;base64,'.base64_encode('not really a jpeg');

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY + ['image' => $image])->assertOk();

        $this->assertSame($image, $planner->calls[0][5]);
        $meta = UsageEntry::where('kind', 'reserve')->sole()->meta;
        $this->assertSame(strlen($image), $meta['image_bytes']);
        $this->assertStringNotContainsString('base64', json_encode($meta));
    }

    public function test_only_image_data_urls_are_accepted(): void
    {
        [, $token] = $this->site(budget: 100_000);
        $this->fake(new EditPlan('ok', [], 'test-model', 300, 40));

        foreach (['https://example.com/a.jpg', 'data:text/html;base64,PGI+', 'data:image/svg+xml;base64,PHN2Zz4='] as $bad) {
            $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY + ['image' => $bad])->assertUnprocessable();
        }
    }

    public function test_media_must_be_web_urls(): void
    {
        [, $token] = $this->site(budget: 100_000);
        $this->fake(new EditPlan('ok', [], 'test-model', 300, 40));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY + [
            'media' => [['id' => 7, 'url' => 'javascript:alert(1)']],
        ])->assertUnprocessable();
    }

    public function test_charged_failure_is_still_settled(): void
    {
        [$site, $token] = $this->site(budget: 10_000);
        $this->fake(new EditPlannerException('The AI declined this request.', new EditPlan('', [], 'claude-opus-5-5', 300, 20)));

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)->assertStatus(502);

        $this->assertSame(['reserve', 'settle'], UsageEntry::orderBy('id')->pluck('kind')->all());
        $this->assertSame(320, UsageEntry::where('kind', 'settle')->value('tokens'));
    }

    public function test_validates_and_needs_a_site_token(): void
    {
        [, $token] = $this->site();
        $this->fake(new EditPlan('x', [], 'claude-opus-5-5', 1, 1));

        $this->postJson('/api/v1/site/edit-requests', self::BODY)->assertUnauthorized();
        $this->withToken($token)->postJson('/api/v1/site/edit-requests', ['page_id' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page_id', 'instruction', 'sections', 'elements']);
    }

    public function test_rejects_oversized_pages(): void
    {
        [, $token] = $this->site();
        $this->fake(new EditPlan('x', [], 'claude-opus-5-5', 1, 1));
        config(['gama.ai.max_context_bytes' => 50]);

        $this->withToken($token)->postJson('/api/v1/site/edit-requests', self::BODY)->assertStatus(413);
    }

    private function fake(EditPlan|EditPlannerException $result, int $estimate = 1_000): FakeEditPlanner
    {
        $planner = new FakeEditPlanner($result, $estimate);
        $this->app->instance(EditPlanner::class, $planner);

        return $planner;
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
