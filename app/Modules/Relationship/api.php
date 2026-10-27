<?php

// Compatibility loader for the existing protected module loader.
$relationshipApiRoutes = true;
require __DIR__.'/routes.php';
unset($relationshipApiRoutes);
