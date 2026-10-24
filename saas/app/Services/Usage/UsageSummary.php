<?php

namespace App\Services\Usage;

final class UsageSummary
{
    public function __construct(
        public readonly ?int $budget,
        public readonly int $used,
        public readonly int $held,
        public readonly int $estimatedCostMicros,
        public readonly string $periodStart,
    ) {}

    /** Tokens that can still be reserved, or null when there is no budget. */
    public function available(): ?int
    {
        return $this->budget === null ? null : max(0, $this->budget - $this->used - $this->held);
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'period_start' => $this->periodStart,
            'budget' => $this->budget,
            'used' => $this->used,
            'held' => $this->held,
            'available' => $this->available(),
            'estimated_cost_micros' => $this->estimatedCostMicros,
        ];
    }
}
