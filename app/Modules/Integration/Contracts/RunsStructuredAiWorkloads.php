<?php

namespace App\Modules\Integration\Contracts;

use App\Modules\Integration\Support\StructuredAiWorkloadRequest;
use App\Modules\Integration\Support\StructuredAiWorkloadResult;

interface RunsStructuredAiWorkloads
{
    public function execute(
        StructuredAiWorkloadRequest $request,
    ): StructuredAiWorkloadResult;
}
