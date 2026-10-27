<?php

// Compatibility with the central module API loader; Knowledge owns its route definitions.
$knowledgeApiRoutes = true;
require __DIR__.'/routes.php';
unset($knowledgeApiRoutes);
