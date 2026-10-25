<?php

// Compatible loader; all Economy domain route definitions live in routes.php.
$economyApiRoutes = true;
require __DIR__.'/routes.php';
unset($economyApiRoutes);
