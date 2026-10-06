<?php

// Live writes require a separately verified provider contract and a reviewed pilot.
return [
    'enabled' => env('TRIPLETEX_ENABLED', false),
    'writes_enabled' => env('TRIPLETEX_WRITES_ENABLED', false),
];
