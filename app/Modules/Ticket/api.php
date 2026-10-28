<?php

// Compatibility loader entry: all Ticket HTTP routes are owned by routes.php.
// The API bootstrap loads that file under its guarded API registration mode.

// Load only the existing Relationship-owned Ticket API adapter when this compatibility entry is used.
$ticketRelationshipApiRoutes = true;
require __DIR__.'/routes.php';
unset($ticketRelationshipApiRoutes);
