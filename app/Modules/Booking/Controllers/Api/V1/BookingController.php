<?php

namespace App\Modules\Booking\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Booking\Actions\BookingApiWorkspace;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Typed HTTP adapters for the shared Booking workspace. */
class BookingController extends Controller
{
    public function __construct(private BookingApiWorkspace $workspace) {}

    public function publicServices(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspace->publicServices($request)], 200);
    }

    public function publicContext(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json(['data' => $this->workspace->publicContext($request, $setting)], 200);
    }

    public function publicSlots(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json(['data' => $this->workspace->publicSlots($request, $setting)], 200);
    }

    public function submit(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json($this->workspace->submit($request, $setting), 202);
    }

    public function context(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspace->context($request)], 200);
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspace->summary($request)], 200);
    }

    public function settings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspace->settings($request)], 200);
    }

    public function setting(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json(['data' => $this->workspace->setting($request, $setting)], 200);
    }

    public function saveSetting(Request $request): JsonResponse
    {
        return response()->json($this->workspace->saveSetting($request), 201);
    }

    public function updateSetting(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json($this->workspace->saveSetting($request, $setting), 200);
    }

    public function toggle(Request $request, BookingServiceSetting $setting): JsonResponse
    {
        return response()->json($this->workspace->toggle($request, $setting), 200);
    }

    public function requests(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->workspace->requests($request)], 200);
    }

    public function bookingRequest(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        return response()->json(['data' => $this->workspace->bookingRequest($request, $bookingRequest)], 200);
    }

    public function events(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        return response()->json(['data' => $this->workspace->events($request, $bookingRequest)], 200);
    }

    public function confirm(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        return response()->json($this->workspace->decide($request, $bookingRequest, true), 200);
    }

    public function decline(Request $request, BookingRequest $bookingRequest): JsonResponse
    {
        return response()->json($this->workspace->decide($request, $bookingRequest, false), 200);
    }

    public function command(Request $request, string $command): JsonResponse
    {
        return response()->json(['data' => $this->workspace->command($request, $command)], 200);
    }
}
