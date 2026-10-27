<?php

// Compatibility loader; all Signal routes are domain-owned in routes.php.
$signalApiRoutes = true;
require __DIR__.'/routes.php';
unset($signalApiRoutes);
