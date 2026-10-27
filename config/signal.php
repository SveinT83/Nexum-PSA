<?php

return [
    // API availability never activates external sends, supplier processing or provider workers.
    'api_external_execution_enabled' => env('SIGNAL_API_EXTERNAL_EXECUTION_ENABLED', false),
];
