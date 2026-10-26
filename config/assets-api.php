<?php

// Installing an API never activates provider requests or downstream alert rules.
return ['provider_execution_enabled' => env('ASSETS_API_PROVIDER_EXECUTION_ENABLED', false)];
