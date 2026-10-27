<?php

// Compatibility loader: signed domain routes remain owned by routes.php.
$relationshipPublicApiRoutes = true;
require __DIR__.'/routes.php';
unset($relationshipPublicApiRoutes);
