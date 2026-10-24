<?php

return [

    'ai' => [
        'model' => env('GAMA_AI_MODEL', 'claude-opus-5-5'),
        // low, medium, high, xhigh or max. Small edits rarely need more than medium.
        'effort' => env('GAMA_AI_EFFORT', 'medium'),
        'max_tokens' => (int) env('GAMA_AI_MAX_TOKENS', 16000),
        // Largest page model plus element settings accepted from the plugin, in bytes.
        'max_context_bytes' => 200_000,
    ],

    /*
    | Prices used for the estimated cost column of the usage ledger, per model,
    | in millionths of a US dollar per million tokens. Check them against the
    | provider's current price list. Unknown models are estimated at 0, and the
    | provider-reported cost is stored separately when it is available.
    */
    'pricing' => [
        // Anthropic list prices, October 2026: $4 / $20 per MTok, cache write 1.25x, cache read $0.20.
        'claude-opus-5-5' => [
            'input' => 4_000_000,
            'output' => 20_000_000,
            'cache_write' => 5_000_000,
            'cache_read' => 200_000,
        ],
    ],

];
