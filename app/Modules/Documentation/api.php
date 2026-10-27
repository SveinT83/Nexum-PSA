<?php

// Compatibility entry point for the existing API module loader.
$documentationApiRoutes = true;
require __DIR__.'/routes.php';
unset($documentationApiRoutes);
