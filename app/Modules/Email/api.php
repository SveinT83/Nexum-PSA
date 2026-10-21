<?php

// Compatibility loader. All Email domain routes are registered in routes.php.
$tdpsaLoadingEmailApiRoutes = true;
require __DIR__.'/routes.php';
unset($tdpsaLoadingEmailApiRoutes);
