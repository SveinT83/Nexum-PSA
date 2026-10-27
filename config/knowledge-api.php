<?php

// API availability never activates the existing BookStack integration or worker.
return [
    'provider_execution_enabled' => (bool) env('KNOWLEDGE_API_PROVIDER_EXECUTION_ENABLED', false),
];
