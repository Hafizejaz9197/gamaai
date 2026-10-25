<?php

namespace App\Services\Usage;

use App\Models\Account;
use App\Models\Site;
use App\Models\UsageEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Writes the usage ledger and answers "how much is left".
 *
 * Every AI call is bracketed: reserve() holds the most the call may use and
 * fails when the month's budget cannot cover it; settle() records what the
 * provider actually used; release() drops a reservation whose call never
 * happened. Reservations are taken under a lock on the account row, so two
 * requests cannot both spend the last of a budget.
 */
class UsageMeter
{
    /**
     * Hold tokens for an upcoming AI call.
     *
     * @param  array<string, mixed>  $meta
     * @return string Request id to settle or release.
     *
     * @throws BudgetExceeded
     */
    public function reserve(Account $account, ?Site $site, int $tokens, string $purpose, array $meta = []): string
    {
        if ($tokens <= 0) {
            throw new InvalidArgumentException('A reservation must be for at least one token.');
        }

        return DB::transaction(function () use ($account, $site, $tokens, $purpose, $meta) {
            $locked = Account::whereKey($account->getKey())->lockForUpdate()->firstOrFail();
            $available = $this->summary($locked)->available();
            if ($available !== null && $tokens > $available) {
                throw new BudgetExceeded($tokens, $available);
            }

            $requestId = (string) Str::uuid();
            UsageEntry::create([
                'account_id' => $locked->id,
                'site_id' => $site?->id,
                'request_id' => $requestId,
                'kind' => UsageEntry::RESERVE,
                'purpose' => $purpose,
                'tokens' => $tokens,
                'meta' => $meta ?: null,
            ]);

            return $requestId;
        });
    }

    /**
     * Record what an AI call used. Usage above the reservation is still
     * recorded: the provider has already charged for it.
     *
     * @param  array<string, mixed>  $meta  Request details kept with the row, such as attempts and duration.
     */
    public function settle(
        string $requestId,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?int $providerCostMicros = null,
        int $cacheWriteTokens = 0,
        int $cacheReadTokens = 0,
        array $meta = [],
    ): UsageEntry {
        if (min($inputTokens, $outputTokens, $cacheWriteTokens, $cacheReadTokens) < 0) {
            throw new InvalidArgumentException('Token counts cannot be negative.');
        }

        return $this->close($requestId, [
            'kind' => UsageEntry::SETTLE,
            'model' => $model,
            'tokens' => $inputTokens + $outputTokens + $cacheWriteTokens + $cacheReadTokens,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'estimated_cost_micros' => $this->estimateCost($model, $inputTokens, $outputTokens, $cacheWriteTokens, $cacheReadTokens),
            'provider_cost_micros' => $providerCostMicros,
            'meta' => (($cacheWriteTokens || $cacheReadTokens)
                ? ['cache_write_tokens' => $cacheWriteTokens, 'cache_read_tokens' => $cacheReadTokens]
                : []) + $meta ?: null,
        ]);
    }

    /**
     * Drop a reservation whose AI call did not happen or was refused
     * without charge (such as a rate limit). $meta says what happened.
     *
     * @param  array<string, mixed>  $meta
     */
    public function release(string $requestId, array $meta = []): UsageEntry
    {
        return $this->close($requestId, ['kind' => UsageEntry::RELEASE, 'tokens' => 0, 'meta' => $meta ?: null]);
    }

    /**
     * Correct usage by hand (a refund is negative). Kept as its own row so
     * the ledger still shows what happened.
     */
    public function adjust(Account $account, int $tokens, string $reason): UsageEntry
    {
        return UsageEntry::create([
            'account_id' => $account->id,
            'request_id' => (string) Str::uuid(),
            'kind' => UsageEntry::ADJUST,
            'purpose' => 'adjustment',
            'tokens' => $tokens,
            'meta' => ['reason' => $reason],
        ]);
    }

    /**
     * Usage in the calendar month (UTC) containing $at. Open reservations
     * count as held whenever they were made.
     */
    public function summary(Account $account, ?CarbonImmutable $at = null): UsageSummary
    {
        $start = ($at ?? CarbonImmutable::now('UTC'))->utc()->startOfMonth();
        $end = $start->addMonth();

        $month = UsageEntry::where('account_id', $account->id)
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->whereIn('kind', [UsageEntry::SETTLE, UsageEntry::ADJUST]);

        $held = UsageEntry::where('account_id', $account->id)
            ->where('kind', UsageEntry::RESERVE)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('usage_entries as closed')
                    ->whereColumn('closed.request_id', 'usage_entries.request_id')
                    ->whereIn('closed.kind', [UsageEntry::SETTLE, UsageEntry::RELEASE]);
            })
            ->sum('tokens');

        return new UsageSummary(
            budget: $account->monthly_token_budget,
            used: (int) (clone $month)->sum('tokens'),
            held: (int) $held,
            estimatedCostMicros: (int) (clone $month)->sum('estimated_cost_micros'),
            periodStart: $start->toIso8601String(),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function close(string $requestId, array $fields): UsageEntry
    {
        return DB::transaction(function () use ($requestId, $fields) {
            $reservation = UsageEntry::where('request_id', $requestId)
                ->where('kind', UsageEntry::RESERVE)
                ->lockForUpdate()
                ->first();
            if ($reservation === null) {
                throw new LogicException("No reservation {$requestId}.");
            }
            $closed = UsageEntry::where('request_id', $requestId)
                ->whereIn('kind', [UsageEntry::SETTLE, UsageEntry::RELEASE])
                ->exists();
            if ($closed) {
                throw new LogicException("Reservation {$requestId} is already settled or released.");
            }

            return UsageEntry::create($fields + [
                'account_id' => $reservation->account_id,
                'site_id' => $reservation->site_id,
                'request_id' => $requestId,
                'purpose' => $reservation->purpose,
            ]);
        });
    }

    private function estimateCost(string $model, int $inputTokens, int $outputTokens, int $cacheWriteTokens, int $cacheReadTokens): int
    {
        $price = config('gama.pricing', [])[$model] ?? null; // Model ids can contain dots.
        if (! is_array($price)) {
            return 0;
        }

        return intdiv(
            $inputTokens * (int) ($price['input'] ?? 0)
            + $outputTokens * (int) ($price['output'] ?? 0)
            + $cacheWriteTokens * (int) ($price['cache_write'] ?? $price['input'] ?? 0)
            + $cacheReadTokens * (int) ($price['cache_read'] ?? $price['input'] ?? 0),
            1_000_000,
        );
    }
}
