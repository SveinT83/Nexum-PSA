<?php

namespace App\Modules\System\Controllers\Admin;

use App\Modules\System\Queries\ApplicationVersionStatusQuery;
use Illuminate\Http\JsonResponse;

final class ApplicationVersionStatusController
{
    public function __invoke(ApplicationVersionStatusQuery $versionStatus): JsonResponse
    {
        return response()->json($versionStatus->get());
    }
}
