<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI call failed or returned something unusable. When the provider
 * still charged for the call, $plan carries the usage so it can be settled.
 */
class EditPlannerException extends RuntimeException
{
    public function __construct(string $message, public readonly ?EditPlan $plan = null)
    {
        parent::__construct($message);
    }
}
