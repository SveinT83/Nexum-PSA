<?php

// Preserve existing module loading while keeping registrations in routes.php.
$contactApiRoutes = true;
require __DIR__.'/routes.php';
unset($contactApiRoutes);
