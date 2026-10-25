<?php

// API availability never enables existing provider connections or billable writes.
return ['provider_writes_enabled' => env('CLIENTS_API_PROVIDER_WRITES_ENABLED', false)];
