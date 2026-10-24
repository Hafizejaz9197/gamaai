<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\UsageEntry;
use App\Services\Usage\BudgetExceeded;
use App\Services\Usage\UsageMeter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class UsageMeterTest extends TestCase
{
    use RefreshDatabase;

    private UsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->meter = new UsageMeter;
        config(['gama.pricing' => ['test-model-1.0' => ['input' => 3_000_000, 'output' => 15_000_000]]]);
    }

    public function test_reserve_settle_and_summary(): void
    {
        $account = Account::factory()->create(['monthly_token_budget' => 10_000]);

        $request = $this->meter->reserve($account, null, 4_000, 'small_edit');
        $this->assertSame(['used' => 0, 'held' => 4_000, 'available' => 6_000], $this->numbers($account));

        $entry = $this->meter->settle($request, 'test-model-1.0', 1_000, 500, 12_000);
        $this->assertSame(['used' => 1_500, 'held' => 0, 'available' => 8_500], $this->numbers($account));
        // 1000 * $3/M + 500 * $15/M = $0.0105
        $this->assertSame(10_500, $entry->estimated_cost_micros);
        $this->assertSame(12_000, $entry->provider_cost_micros);
        $this->assertSame('small_edit', $entry->purpose);
    }

    public function test_reserve_fails_when_the_budget_cannot_cover_it(): void
    {
        $account = Account::factory()->create(['monthly_token_budget' => 5_000]);
        $this->meter->reserve($account, null, 3_000, 'small_edit');

        try {
            $this->meter->reserve($account, null, 2_001, 'small_edit');
            $this->fail('Expected the budget to be exceeded.');
        } catch (BudgetExceeded $e) {
            $this->assertSame(2_000, $e->available);
        }
        $this->assertSame(1, UsageEntry::count());
    }

    public function test_release_frees_the_hold(): void
    {
        $account = Account::factory()->create(['monthly_token_budget' => 5_000]);
        $request = $this->meter->reserve($account, null, 5_000, 'small_edit');

        $this->meter->release($request);

        $this->assertSame(['used' => 0, 'held' => 0, 'available' => 5_000], $this->numbers($account));
    }

    public function test_a_reservation_closes_once(): void
    {
        $account = Account::factory()->create();
        $request = $this->meter->reserve($account, null, 100, 'small_edit');
        $this->meter->settle($request, 'test-model-1.0', 10, 10);

        $this->expectException(LogicException::class);
        $this->meter->release($request);
    }

    public function test_unlimited_accounts_still_record_usage(): void
    {
        $account = Account::factory()->create(['monthly_token_budget' => null]);
        $request = $this->meter->reserve($account, null, 1_000_000_000, 'redesign');
        $this->meter->settle($request, 'unknown-model', 100, 50);

        $summary = $this->meter->summary($account);
        $this->assertNull($summary->available());
        $this->assertSame(150, $summary->used);
        $this->assertSame(0, $summary->estimatedCostMicros);
    }

    public function test_usage_counts_per_calendar_month_and_adjustments_count(): void
    {
        $account = Account::factory()->create(['monthly_token_budget' => 1_000]);
        CarbonImmutable::setTestNow('2026-09-30 23:00:00');
        $this->meter->settle($this->meter->reserve($account, null, 500, 'small_edit'), 'test-model-1.0', 400, 0);
        CarbonImmutable::setTestNow('2026-10-01 01:00:00');
        $this->meter->adjust($account, -100, 'Refund for a failed edit');

        $this->assertSame(400, $this->meter->summary($account, CarbonImmutable::parse('2026-09-15'))->used);
        $this->assertSame(-100, $this->meter->summary($account, CarbonImmutable::parse('2026-10-15'))->used);
        CarbonImmutable::setTestNow();
    }

    public function test_ledger_rows_cannot_be_changed_or_deleted(): void
    {
        $account = Account::factory()->create();
        $this->meter->reserve($account, null, 100, 'small_edit');
        $entry = UsageEntry::first();

        try {
            $entry->update(['tokens' => 1]);
            $this->fail('Expected the update to be refused.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    /**
     * @return array<string, int|null>
     */
    private function numbers(Account $account): array
    {
        $summary = $this->meter->summary($account);

        return ['used' => $summary->used, 'held' => $summary->held, 'available' => $summary->available()];
    }
}
