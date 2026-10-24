<?php

namespace App\Services\Ai;

/**
 * What the AI proposes for one edit request, and what it cost.
 */
final class EditPlan
{
    /**
     * @param  list<array<string, mixed>>  $operations  Edit operations in the plugin's format (docs/edit-operations.md).
     */
    public function __construct(
        public readonly string $summary,
        public readonly array $operations,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cacheWriteTokens = 0,
        public readonly int $cacheReadTokens = 0,
    ) {}
}
