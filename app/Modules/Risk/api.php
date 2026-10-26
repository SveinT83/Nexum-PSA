<?php

// Compatibility loader; Risk routes are owned by routes.php.
$riskApiRoutes = true;
require __DIR__.'/routes.php';
unset($riskApiRoutes);
