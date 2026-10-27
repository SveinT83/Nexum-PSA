<?php

// Compatibility loader; domain registrations live in routes.php.
$notificationApiRoutes = true;
require __DIR__.'/routes.php';
unset($notificationApiRoutes);
