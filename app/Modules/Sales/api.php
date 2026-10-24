<?php

// Compatibility entry point; authoritative domain route declarations live in routes.php.
$salesStaffApiRoutes = true;
require __DIR__.'/routes.php';
unset($salesStaffApiRoutes);
