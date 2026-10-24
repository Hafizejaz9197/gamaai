<?php

namespace Tests\Support;

use App\Services\Ai\EditPlan;
use App\Services\Ai\EditPlanner;
use App\Services\Ai\EditPlannerException;

class FakeEditPlanner implements EditPlanner
{
    /** @var list<array{0: string, 1: array, 2: array}> */
    public array $calls = [];

    public function __construct(
        private readonly EditPlan|EditPlannerException $result,
        private readonly int $estimate = 1_000,
    ) {}

    public function plan(string $instruction, array $sections, array $elements): EditPlan
    {
        $this->calls[] = [$instruction, $sections, $elements];
        if ($this->result instanceof EditPlannerException) {
            throw $this->result;
        }

        return $this->result;
    }

    public function estimateTokens(string $instruction, array $sections, array $elements): int
    {
        return $this->estimate;
    }
}
