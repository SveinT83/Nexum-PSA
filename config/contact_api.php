<?php

return [
    // API discovery/preview stays available; sending requires separate installation activation.
    'portal_invitation_execution_enabled' => env('CONTACT_API_PORTAL_INVITATIONS_ENABLED', false),
];
