<?php

// API availability does not activate real device/provider test delivery.
return ['external_tests_enabled' => env('NOTIFICATION_API_EXTERNAL_TESTS_ENABLED', false)];
