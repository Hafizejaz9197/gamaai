<?php

namespace App\Services\Ai;

interface EditPlanner
{
    /**
     * Turn a request in plain words into edit operations for one page.
     *
     * @param  list<array<string, mixed>>  $sections  Page model from the plugin: the whole page, or the part $scope names.
     * @param  array<string, array<string, mixed>>  $elements  Settings of the element types the edit may use.
     * @param  string|null  $scope  Which part of the page $sections is, when it is not all of it.
     *
     * @throws EditPlannerException
     */
    public function plan(string $instruction, array $sections, array $elements, ?string $scope = null): EditPlan;

    /** Upper bound of tokens one call may use, for the budget reservation. */
    public function estimateTokens(string $instruction, array $sections, array $elements, ?string $scope = null): int;

    /** The most output tokens one call may produce (max_tokens). */
    public function maxOutputTokens(): int;
}
