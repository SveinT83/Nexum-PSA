<?php

// Compatibility loader; all Client routes are owned by the module entry point.
$clientsApiRoutes = true;
require __DIR__.'/routes.php';
unset($clientsApiRoutes);
