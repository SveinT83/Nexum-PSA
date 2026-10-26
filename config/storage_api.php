<?php

// API execution is separately gated; ordinary browser automation settings are unchanged.
return [
    'automation_enabled' => env('STORAGE_API_AUTOMATION_ENABLED', false),
    'ai_repair_enabled' => env('STORAGE_API_AI_REPAIR_ENABLED', false),
];
