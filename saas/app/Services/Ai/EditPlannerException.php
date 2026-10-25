<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI call failed or returned something unusable. When the provider
 * still charged for the call, $plan carries the usage so it can be settled.
 * A rate limit (HTTP 429) sets $rateLimited, with the wait the provider
 * asked for in $retryAfter when it said one. $attempts counts the calls
 * made, including the one safe retry after a short rate limit.
 */
class EditPlannerException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?EditPlan $plan = null,
        public readonly bool $rateLimited = false,
        public readonly ?int $retryAfter = null,
        public readonly int $attempts = 1,
    ) {
        parent::__construct($message);
    }
}
