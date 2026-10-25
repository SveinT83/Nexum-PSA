<?php

// Compatibility with the existing API loader; DataExchange owns declarations in routes.php.
$dataExchangeApiRoutes = true;
require __DIR__.'/routes.php';
unset($dataExchangeApiRoutes);
