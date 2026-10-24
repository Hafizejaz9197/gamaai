<?php

namespace App\Services\Usage;

use App\Models\UsageEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Totals of the usage ledger for one calendar month (UTC), for the admin
 * dashboard. Charged usage is settle and adjust rows; reservations only
 * hold tokens and are not spend.
 */
class UsageReport
{
    public const GROUPS = ['account_id', 'site_id', 'model', 'purpose'];

    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $end;

    public function __construct(?CarbonImmutable $month = null, public readonly ?int $accountId = null)
    {
        $this->start = ($month ?? CarbonImmutable::now('UTC'))->utc()->startOfMonth();
        $this->end = $this->start->addMonth();
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function forMonth(?string $month, ?int $accountId = null): self
    {
        if ($month === null || $month === '') {
            return new self(null, $accountId);
        }
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('The month must look like 2026-10.');
        }

        return new self(CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC'), $accountId);
    }

    /**
     * @return array{tokens: int, input_tokens: int, output_tokens: int, estimated_cost_micros: int, provider_cost_micros: int, requests: int, confirmed: int}
     */
    public function totals(): array
    {
        $row = $this->charged()->selectRaw($this->sums())->first();

        return $this->normalize($row?->getAttributes() ?? []);
    }

    /**
     * Charged usage grouped by one column, largest first.
     *
     * @return list<array<string, mixed>>
     */
    public function by(string $column): array
    {
        if (! in_array($column, self::GROUPS, true)) {
            throw new InvalidArgumentException("Cannot group usage by {$column}.");
        }

        return $this->charged()
            ->selectRaw("{$column} as group_key, ".$this->sums())
            ->groupBy($column)
            ->orderByDesc('tokens')
            ->get()
            ->map(fn (UsageEntry $row) => ['key' => $row->getAttributes()['group_key']] + $this->normalize($row->getAttributes()))
            ->all();
    }

    /** Reservations released because the AI call never happened. */
    public function releases(): int
    {
        return $this->query()->where('kind', UsageEntry::RELEASE)->count();
    }

    /**
     * @return Builder<UsageEntry>
     */
    public function query(): Builder
    {
        return UsageEntry::query()
            ->where('created_at', '>=', $this->start)
            ->where('created_at', '<', $this->end)
            ->when($this->accountId !== null, fn (Builder $q) => $q->where('account_id', $this->accountId));
    }

    /**
     * @return Builder<UsageEntry>
     */
    private function charged(): Builder
    {
        return $this->query()->whereIn('kind', [UsageEntry::SETTLE, UsageEntry::ADJUST]);
    }

    private function sums(): string
    {
        return 'COALESCE(SUM(tokens), 0) as tokens, COALESCE(SUM(input_tokens), 0) as input_tokens, '
            .'COALESCE(SUM(output_tokens), 0) as output_tokens, COALESCE(SUM(estimated_cost_micros), 0) as estimated_cost_micros, '
            .'COALESCE(SUM(provider_cost_micros), 0) as provider_cost_micros, '
            ."SUM(CASE WHEN kind = 'settle' THEN 1 ELSE 0 END) as requests, COUNT(provider_cost_micros) as confirmed";
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{tokens: int, input_tokens: int, output_tokens: int, estimated_cost_micros: int, provider_cost_micros: int, requests: int, confirmed: int}
     */
    private function normalize(array $row): array
    {
        $out = [];
        foreach (['tokens', 'input_tokens', 'output_tokens', 'estimated_cost_micros', 'provider_cost_micros', 'requests', 'confirmed'] as $key) {
            $out[$key] = (int) ($row[$key] ?? 0);
        }

        return $out;
    }
}
