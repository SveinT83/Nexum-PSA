<?php

namespace App\Modules\Report\Contracts;

/** Domain-owned API discovery descriptors are documentation, never an authorization grant. */
interface ReportApiDefinition
{
    public function apiEndpoints(): array;
}
