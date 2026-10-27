<?php

// Compatibility entry point for the shared module loader; definitions stay domain-owned.
$warroomApiRoutes = true;
require __DIR__.'/routes.php';
unset($warroomApiRoutes);
