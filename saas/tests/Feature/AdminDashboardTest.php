<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Site;
use App\Models\UsageEntry;
use App\Models\User;
use App\Services\Usage\UsageMeter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);
        $user->role = User::ROLE_ADMIN;
        $user->save();

        return $user;
    }

    /**
     * @return array{0: Site, 1: string}
     */
    private function site(?int $budget = null, string $name = 'Shop'): array
    {
        $account = Account::factory()->create(['name' => $name, 'monthly_token_budget' => $budget]);
        $site = new Site(['name' => "{$name} site", 'url' => 'https://example.com']);
        $site->account()->associate($account);
        $token = $site->issueToken();
        $site->save();

        return [$site, $token];
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/')->assertRedirect('/admin');
        $this->get('/admin/login')->assertOk()->assertSee('Log in');
    }

    public function test_members_cannot_open_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_login_and_logout(): void
    {
        $this->admin();

        $this->post('/admin/login', ['email' => 'Admin@Example.com', 'password' => 'password'])
            ->assertRedirect('/admin');
        $this->assertAuthenticated();

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_login_rejects_wrong_passwords_and_non_admins(): void
    {
        $this->admin();
        User::factory()->create(['email' => 'member@example.com']);

        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post('/admin/login', ['email' => 'member@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        $this->admin();
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        }

        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertStringContainsString('Too many attempts', session('errors')->first('email'));
        $this->assertGuest();
    }

    public function test_dashboard_shows_spend_by_account_model_and_type(): void
    {
        config(['gama.pricing' => ['claude-test' => ['input' => 4_000_000, 'output' => 20_000_000]]]);
        [$site] = $this->site(budget: 100_000, name: 'Acme');
        $meter = new UsageMeter;
        $id = $meter->reserve($site->account, $site, 20_000, 'small_edit', ['page_id' => 11]);
        $meter->settle($id, 'claude-test', 3_000, 500); // 3000*4 + 500*20 = 22000 micros
        $meter->release($meter->reserve($site->account, $site, 5_000, 'small_edit'));
        $this->site(name: 'Quiet');

        $this->actingAs($this->admin())->get('/admin')
            ->assertOk()
            ->assertSee('Acme')
            ->assertSee('Quiet')
            ->assertSee('3,500')
            ->assertSee('$0.0220')
            ->assertSee('96,500') // left: 100000 - 3500
            ->assertSee('claude-test')
            ->assertSee('small_edit')
            ->assertSee('1 request released');
    }

    public function test_past_months_and_bad_months(): void
    {
        [$site] = $this->site(name: 'Acme');
        (new UsageMeter)->adjust($site->account, 777, 'test');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin?month=2020-01')->assertOk()->assertSee('January 2020')->assertDontSee('777');
        $this->actingAs($admin)->get('/admin?month=2020-13')->assertNotFound();
    }

    public function test_create_and_update_an_account(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/accounts', ['name' => 'New Co', 'monthly_token_budget' => 5000])
            ->assertRedirect();
        $account = Account::where('name', 'New Co')->firstOrFail();
        $this->assertSame(5000, $account->monthly_token_budget);

        $this->actingAs($admin)->put("/admin/accounts/{$account->id}", ['name' => 'New Co', 'monthly_token_budget' => ''])
            ->assertRedirect();
        $this->assertNull($account->refresh()->monthly_token_budget);

        $this->actingAs($admin)->get("/admin/accounts/{$account->id}")->assertOk()->assertSee('no budget limit');
    }

    public function test_adjustments_are_ledger_rows(): void
    {
        [$site] = $this->site(budget: 1_000);

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$site->account_id}/adjustments", ['tokens' => -200, 'reason' => 'Refund'])
            ->assertRedirect();

        $entry = UsageEntry::where('kind', UsageEntry::ADJUST)->firstOrFail();
        $this->assertSame(-200, $entry->tokens);
        $this->assertSame('Refund (by admin@example.com)', $entry->meta['reason']);
        $this->assertSame(1_200, (new UsageMeter)->summary($site->account)->available());
    }

    public function test_adding_a_site_shows_a_working_token_once(): void
    {
        $account = Account::factory()->create();

        $response = $this->actingAs($this->admin())
            ->post("/admin/accounts/{$account->id}/sites", ['name' => 'Blog', 'url' => 'https://blog.example.com'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        preg_match('/gsk_[A-Za-z0-9]{40}/', $response->getContent(), $match);
        $this->assertNotEmpty($match);

        $this->assertSame('Blog', Site::findByToken($match[0])?->name);
        $this->withToken($match[0])->getJson('/api/v1/site/usage')->assertOk();
        $this->actingAs(User::first())->get("/admin/accounts/{$account->id}")->assertDontSee($match[0]);
    }

    public function test_site_urls_must_be_web_addresses(): void
    {
        $account = Account::factory()->create();

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$account->id}/sites", ['name' => 'Bad', 'url' => 'javascript://x%0Aalert(1)'])
            ->assertSessionHasErrors('url');
    }

    public function test_rotating_a_token_stops_the_old_one(): void
    {
        [$site, $old] = $this->site();

        $response = $this->actingAs($this->admin())->post("/admin/sites/{$site->id}/token")->assertOk();
        preg_match('/gsk_[A-Za-z0-9]{40}/', $response->getContent(), $match);

        $this->assertNull(Site::findByToken($old));
        $this->assertSame($site->id, Site::findByToken($match[0])?->id);
    }

    public function test_ledger_filters_by_account_and_kind(): void
    {
        [$a] = $this->site(name: 'Alpha');
        [$b] = $this->site(name: 'Beta');
        $meter = new UsageMeter;
        $meter->adjust($a->account, 111, 'alpha note');
        $meter->adjust($b->account, 222, 'beta note');
        $meter->reserve($a->account, $a, 333, 'small_edit');
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/ledger')->assertOk()->assertSee('alpha note')->assertSee('beta note');
        $this->actingAs($admin)->get("/admin/ledger?account={$a->account_id}")
            ->assertSee('alpha note')->assertDontSee('beta note')->assertSee('333');
        $this->actingAs($admin)->get("/admin/ledger?account={$a->account_id}&kind=adjust")
            ->assertSee('alpha note')->assertDontSee('>333<', false);
    }

    public function test_admin_create_command(): void
    {
        $this->artisan('gama:admin-create', ['email' => 'Boss@Example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Type it again', 'correct horse battery')
            ->assertSuccessful();

        $user = User::where('email', 'boss@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());

        $this->artisan('gama:admin-create', ['email' => 'boss@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'short')
            ->assertFailed();
        $this->artisan('gama:admin-create', ['email' => 'boss@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'correct horse battery')
            ->expectsQuestion('Type it again', 'something else entirely')
            ->assertFailed();
    }
}
