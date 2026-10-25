<?php

// Compatible loader shim; all Commercial routes belong to the domain entry point.
$commercialApiRoutes = true;
require __DIR__.'/routes.php';
unset($commercialApiRoutes);
