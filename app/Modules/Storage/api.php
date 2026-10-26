<?php

// Compatibility loader for the existing module API discovery; declarations stay in routes.php.
$storageApiRoutes = true;
require __DIR__.'/routes.php';
unset($storageApiRoutes);
