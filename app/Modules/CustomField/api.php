<?php

// Compatibility entry point; the canonical module file owns both web and API routes.
$customFieldApiRoutes = true;
require __DIR__.'/routes.php';
unset($customFieldApiRoutes);
