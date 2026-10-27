<?php

// API-origin external AI, discovery and provider writes require explicit activation.
return ['api_external_execution' => env('LEAD_INTELLIGENCE_API_EXTERNAL_EXECUTION', false)];
