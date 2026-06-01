<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Report Definitions
    |--------------------------------------------------------------------------
    |
    | The Report domain owns the shared hub, while modules own their report
    | definitions and calculations. Add new definitions here after the owning
    | module has implemented the report route, query, tests, and docs.
    |
    */
    'definitions' => [
        App\Modules\Ticket\Reports\TicketSlaReportDefinition::class,
    ],
];
