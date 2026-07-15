<?php

namespace App\Modules\Booking\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(): View
    {
        return view('booking::Admin.index', [
            'settings' => BookingServiceSetting::query()
                ->with(['service', 'assignedUser'])
                ->withCount('requests')
                ->orderBy('public_name')
                ->get(),
            'requests' => BookingRequest::query()
                ->with(['setting.service', 'assignedUser'])
                ->latest()
                ->limit(25)
                ->get(),
            'openRequestCount' => BookingRequest::query()
                ->where('status', BookingRequest::STATUS_REQUESTED)
                ->count(),
        ]);
    }
}
