<?php

// Compatibility loader: canonical API registration belongs to the module routes.
$marketingApiRoutes = true;
require __DIR__.'/routes.php';
unset($marketingApiRoutes);
