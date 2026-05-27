<?php

namespace App\Modules\Commercial\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class TimeRateController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('tech.rates.index');
    }

    public function store(): RedirectResponse
    {
        return redirect()->route('tech.rates.index');
    }

    public function update(): RedirectResponse
    {
        return redirect()->route('tech.rates.index');
    }
}
