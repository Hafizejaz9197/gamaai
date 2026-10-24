<?php

namespace App\Services\Ai;

interface EditPlanner
{
    /**
     * Turn a request in plain words into edit operations for one page.
     *
     * @param  list<array<string, mixed>>  $sections  Page model from the plugin.
     * @param  array<string, array<string, mixed>>  $elements  Settings of the element types the edit may use.
     *
     * @throws EditPlannerException
     */
    public function plan(string $instruction, array $sections, array $elements): EditPlan;

    /** Upper bound of tokens one call may use, for the budget reservation. */
    public function estimateTokens(string $instruction, array $sections, array $elements): int;
}
