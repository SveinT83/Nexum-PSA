<?php

// Compatibility loader; all domain route declarations are owned by routes.php.
$leadApiRoutes = true;
require __DIR__.'/routes.php';
unset($leadApiRoutes);
