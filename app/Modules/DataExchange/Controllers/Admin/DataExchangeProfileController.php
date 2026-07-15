<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use Illuminate\View\View;

class DataExchangeProfileController extends Controller
{
    public function create(): View
    {
        abort_unless(request()->user()?->can('data_exchange.manage'), 403);

        return view('dataexchange::Admin.builder', [
            'profile' => new DataExchangeProfile(),
        ]);
    }

    public function edit(DataExchangeProfile $profile): View
    {
        abort_unless(request()->user()?->can('data_exchange.manage'), 403);

        return view('dataexchange::Admin.builder', [
            'profile' => $profile,
        ]);
    }
}
