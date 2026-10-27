<?php

// Compatibility loader; Intake owns every domain route in routes.php.
$intakeApiRoutes = true;
require __DIR__.'/routes.php';
unset($intakeApiRoutes);
