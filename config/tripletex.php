<?php

// Customer writes follow the saved GUI setting; the separate write runtime below controls time transfer.
return [
    'enabled' => env('TRIPLETEX_ENABLED', false),
    'writes_enabled' => env('TRIPLETEX_WRITES_ENABLED', false),
];
