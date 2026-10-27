<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingApiCommand;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Booking\Support\BookingApiAccess;
use App\Modules\Booking\Support\BookingApiInput;
use App\Modules\Booking\Support\BookingApiPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The official workspace shares native domain actions and exposes only documented inputs/results. */
class BookingApiWorkspace
{
    public function __construct(private FindBookingSlots $slots, private SaveBookingServiceSetting $settings,
        private ExecuteBookingApiCommand $commands) {}

    public function publicServices(Request $request): array
    {
        BookingApiInput::only($request, ['page', 'per_page']);

        return $this->page($request, BookingServiceSetting::query()->bookable()->with(['service', 'assignedUser', 'eligibleUsers'])->orderBy('public_name'),
            fn ($setting) => BookingApiPayload::publicSetting($setting));
    }

    public function publicContext(Request $request, BookingServiceSetting $setting): array
    {
        BookingApiInput::only($request, []);
        $this->bookable($setting);

        return ['service' => BookingApiPayload::publicSetting($setting), 'timezone' => $this->slots->publicTimezone(),
            'technicians' => $this->slots->eligibleUsersForPublic($setting)->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->all(),
            'privacy_acknowledgement_required' => true, 'honeypot_field' => $setting->spam_honeypot_field ?: 'booking_website',
            'request_mode' => 'staff_confirmed', 'api_submissions_enabled' => (bool) config('booking.api_public_submissions_enabled')];
    }

    public function publicSlots(Request $request, BookingServiceSetting $setting): array
    {
        BookingApiInput::only($request, ['date', 'technician_id', 'limit']);
        $this->bookable($setting);
        $input = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'technician_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $selected = null;
        if ($setting->allowsCustomerTechnicianChoice() && isset($input['technician_id'])) {
            $selected = $this->slots->eligibleUsersForPublic($setting)->firstWhere('id', (int) $input['technician_id']);
            if (! $selected) {
                throw \Illuminate\Validation\ValidationException::withMessages(['technician_id' => 'Choose an available technician for this service.']);
            }
        }
        $timezone = $this->slots->publicTimezone();
        $from = isset($input['date']) ? Carbon::parse($input['date'], $timezone)->startOfDay() : now($timezone);
        $slots = $setting->allowsCustomerTechnicianChoice() && ! $selected ? collect()
            : $this->slots->forSetting($setting, $from, $from->copy()->addDays(14)->endOfDay(), (int) ($input['limit'] ?? 40), $selected);

        return ['timezone' => $timezone, 'slots' => $slots->map(fn ($slot) => [
            'starts_at' => $slot['starts_at']->toIso8601String(), 'ends_at' => $slot['ends_at']->toIso8601String(), 'timezone' => $slot['timezone'],
        ])->all()];
    }

    public function submit(Request $request, BookingServiceSetting $setting): array
    {
        $this->bookable($setting);
        $field = $setting->spam_honeypot_field ?: 'booking_website';
        BookingApiInput::only($request, ['company_name', 'contact_name', 'contact_email', 'contact_phone', 'message',
            'slot_starts_at', 'timezone', 'technician_id', 'privacy_acknowledged', $field]);
        // Bound even spam inputs before the native spam path stores any source evidence.
        $request->validate([$field => ['nullable', 'string', 'max:80'], 'contact_name' => ['nullable', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'], 'contact_email' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:80'], 'message' => ['nullable', 'string', 'max:2000'],
            'slot_starts_at' => ['nullable', 'string', 'max:80'], 'timezone' => ['nullable', 'string', 'max:80'],
            'technician_id' => ['nullable', 'integer']]);

        return $this->commands->handle($request, 'request.submit', function () use ($request, $setting) {
            $booking = app(StoreBookingRequest::class)->handle($request, $setting->fresh(), true);

            return ['data' => ['reference' => $booking->booking_key, 'message' => 'Your booking request has been received.'],
                'booking_request_id' => $booking->id];
        }, $setting->id);
    }

    public function context(Request $request): array
    {
        BookingApiInput::only($request, ['capability', 'setting_slug']);
        $input = $request->validate(['capability' => ['nullable', Rule::in(['overview', 'configuration', 'review'])],
            'setting_slug' => ['nullable', 'string', 'max:120']]);
        $capability = $input['capability'] ?? 'overview';
        $permission = ['overview' => 'booking.view', 'configuration' => 'booking.manage', 'review' => 'booking.request_review'][$capability];
        BookingApiAccess::authorize($request->user(), $permission);
        $result = ['capability' => $capability, 'api_staff_writes_enabled' => (bool) config('booking.api_staff_writes_enabled'),
            'request_statuses' => ['requested', 'confirmed', 'declined', 'cancelled', 'spam']];
        if ($capability === 'configuration') {
            $current = isset($input['setting_slug']) ? BookingServiceSetting::query()->where('slug', $input['setting_slug'])->firstOrFail() : null;
            $result += ['defaults' => $this->settings->defaults(), 'services' => $this->settings->serviceOptions($current)
                ->map(fn ($service) => ['id' => $service->id, 'name' => $service->name])->all(),
                'users' => $this->settings->userOptions()->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])->all(),
                'constraints' => ['statuses' => ['draft', 'active', 'archived'], 'routing_modes' => ['fixed', 'automatic', 'customer_choice'],
                    'hours_sources' => ['company', 'technician'], 'duration_min' => 15, 'duration_max' => 480, 'slot_steps' => [15, 30, 60],
                    'notice_min' => 0, 'notice_max' => 720, 'horizon_min' => 1, 'horizon_max' => 365]];
        }

        return $result;
    }

    public function summary(Request $request): array
    {
        BookingApiInput::only($request, []);

        return ['open_request_count' => BookingRequest::query()->where('status', 'requested')->count(),
            'settings_count' => BookingServiceSetting::query()->count()];
    }

    public function settings(Request $request): array
    {
        BookingApiInput::only($request, ['page', 'per_page']);

        return $this->page($request, BookingServiceSetting::query()->with(['service', 'assignedUser', 'eligibleUsers'])->withCount('requests')->orderBy('public_name'),
            fn ($setting) => BookingApiPayload::setting($setting));
    }

    public function setting(Request $request, BookingServiceSetting $setting): array
    {
        BookingApiInput::only($request, []);

        return BookingApiPayload::setting($setting);
    }

    public function saveSetting(Request $request, ?BookingServiceSetting $setting = null): array
    {
        BookingApiInput::only($request, array_merge(BookingApiPayload::SETTING_FIELDS, ['eligible_user_ids', 'expected_revision']));

        return $this->commands->handle($request, $setting ? 'setting.update' : 'setting.create', function () use ($request, $setting) {
            if ($setting) {
                $setting = $setting->fresh();
                $this->expected($request, BookingApiPayload::setting($setting)['revision']);
            }

            return ['data' => BookingApiPayload::setting($this->settings->handle($request, $setting))];
        });
    }

    public function toggle(Request $request, BookingServiceSetting $setting): array
    {
        BookingApiInput::only($request, ['expected_revision']);

        return $this->commands->handle($request, 'setting.toggle', function () use ($request, $setting) {
            $this->expected($request, BookingApiPayload::setting($setting->fresh())['revision']);

            return ['data' => BookingApiPayload::setting($this->settings->toggle($setting))];
        });
    }

    public function requests(Request $request): array
    {
        BookingApiInput::only($request, ['page', 'per_page']);

        return $this->page($request, BookingRequest::query()->with(['setting', 'assignedUser'])->latest('id'),
            fn ($booking) => BookingApiPayload::bookingRequest($booking));
    }

    public function bookingRequest(Request $request, BookingRequest $bookingRequest): array
    {
        BookingApiInput::only($request, []);

        return BookingApiPayload::bookingRequest($bookingRequest);
    }

    public function events(Request $request, BookingRequest $bookingRequest): array
    {
        BookingApiInput::only($request, ['page', 'per_page']);

        return $this->page($request, $bookingRequest->events()->with('actor')->latest('id'), fn ($event) => [
            'id' => $event->id, 'type' => $event->type, 'message' => $event->message,
            'actor' => $event->actor ? ['id' => $event->actor->id, 'name' => $event->actor->name] : null,
            'created_at' => $event->created_at?->toIso8601String(),
        ]);
    }

    public function decide(Request $request, BookingRequest $bookingRequest, bool $confirm): array
    {
        BookingApiInput::only($request, $confirm ? ['expected_revision'] : ['expected_revision', 'decline_reason']);
        $request->validate(['decline_reason' => ['nullable', 'string', 'max:1000']]);

        return $this->commands->handle($request, $confirm ? 'request.confirm' : 'request.decline', function () use ($request, $bookingRequest, $confirm) {
            $bookingRequest = $bookingRequest->fresh();
            $this->expected($request, BookingApiPayload::bookingRequest($bookingRequest)['revision']);
            $bookingRequest = $confirm ? app(ConfirmBookingRequest::class)->handle($bookingRequest, $request->user(), true)
                : app(DeclineBookingRequest::class)->handle($bookingRequest, $request->user(), $request->input('decline_reason'), true);

            return ['data' => BookingApiPayload::bookingRequest($bookingRequest), 'booking_request_id' => $bookingRequest->id];
        });
    }

    public function command(Request $request, string $command): array
    {
        BookingApiInput::only($request, []);
        $receipt = BookingApiCommand::query()->whereKey($command)->where('actor_id', $request->user()->id)
            ->where('token_hash', BookingApiAccess::tokenHash($request))->firstOrFail();
        [$scope, $permission] = ExecuteBookingApiCommand::AUTHORITY[$receipt->operation];
        abort_unless($request->user()->tokenCan($scope), 403, 'Original command ability is required.');
        BookingApiAccess::authorize($request->user(), $permission);

        return ['id' => $receipt->id, 'operation' => $receipt->operation, 'result' => $receipt->result,
            'created_at' => $receipt->created_at->toIso8601String()];
    }

    private function bookable(BookingServiceSetting $setting): void
    {
        $setting->loadMissing(['service', 'assignedUser', 'eligibleUsers']);
        abort_unless($setting->isBookable(), 404);
    }

    private function expected(Request $request, string $revision): void
    {
        $input = $request->validate(['expected_revision' => ['required', 'string', 'size:64']]);
        abort_unless(hash_equals($revision, $input['expected_revision']), 409, 'Booking changed; reload and review before retrying.');
    }

    private function page(Request $request, $query, callable $project): array
    {
        $input = $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = $query->paginate((int) ($input['per_page'] ?? 25), ['*'], 'page', (int) ($input['page'] ?? 1));

        return ['items' => $page->getCollection()->map($project)->all(), 'page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
