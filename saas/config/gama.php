<?php

return [

    /*
    | Prices used for the estimated cost column of the usage ledger, per model,
    | in millionths of a US dollar per million tokens. Fill in from the AI
    | provider's current price list. Unknown models are estimated at 0, and
    | the provider-reported cost is stored separately when it is available.
    |
    | 'model-id' => ['input' => 3_000_000, 'output' => 15_000_000],
    */
    'pricing' => [],

];
