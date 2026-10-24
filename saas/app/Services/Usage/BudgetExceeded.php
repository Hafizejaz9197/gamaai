<?php

namespace App\Services\Usage;

use RuntimeException;

class BudgetExceeded extends RuntimeException
{
    public function __construct(public readonly int $requested, public readonly int $available)
    {
        parent::__construct("This account has {$available} tokens left this month; the request needs up to {$requested}.");
    }
}
