<?php

namespace App\Modules\System\Controllers\Admin;

use App\Modules\System\Queries\ApplicationVersionStatusQuery;
use Illuminate\Contracts\View\View;

final class AdminDashboardController
{
    public function __invoke(ApplicationVersionStatusQuery $versionStatus): View
    {
        return view('system::Admin.index', [
            'applicationVersionStatus' => $versionStatus->local(),
        ]);
    }
}
