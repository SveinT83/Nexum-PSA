<?php

namespace App\Modules\Booking\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Booking\Actions\ConfirmBookingRequest;
use App\Modules\Booking\Actions\DeclineBookingRequest;
use App\Modules\Booking\Models\BookingApiCommand;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Booking\Notifications\BookingRequestReceived;
use App\Modules\Booking\Support\BookingApiPayload;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Real bearer/native authority plus successful current browser workflows, retries and safe projections. */
class BookingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private User $technician;

    private BookingServiceSetting $setting;

    private string $token;

    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-04 06:00:00', 'UTC'));
        config(['booking.api_public_submissions_enabled' => true, 'booking.api_staff_writes_enabled' => true]);
        Notification::fake();
        foreach (['booking.view', 'booking.manage', 'booking.request_review'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['booking.view', 'booking.manage', 'booking.request_review']);
        $this->technician = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Private automatic canary']);
        $this->token = $this->actor->createToken('booking-test', ['booking.read', 'booking.settings.write', 'booking.requests.review', 'booking.commands.read'])->plainTextToken;
        $this->setting = BookingServiceSetting::query()->create($this->input());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function input(array $overrides = []): array
    {
        $unit = Units::query()->create(['name' => 'Hour', 'short' => 'h', 'common_code' => 'HUR']);
        $service = Services::query()->create(['name' => 'API consultation', 'sku' => Str::random(12), 'unitId' => $unit->id,
            'status' => 'published', 'orderable' => true, 'taxable' => 25, 'billing_cycle' => 'one_time',
            'price_including_tax' => 1250, 'price_ex_vat' => 1000, 'created_by_user_id' => $this->actor->id]);

        return array_merge(['service_id' => $service->id, 'assigned_user_id' => $this->technician->id,
            'status' => 'active', 'slug' => 'api-'.Str::lower(Str::random(8)), 'public_name' => 'Public consultation',
            'public_description' => 'Public copy', 'booking_mode' => 'staff_confirmed', 'technician_routing_mode' => 'fixed',
            'working_hours_source' => 'company', 'duration_minutes' => 60, 'slot_step_minutes' => 15,
            'min_notice_hours' => 0, 'horizon_days' => 30, 'allow_new_clients' => true, 'spam_honeypot_field' => 'booking_website'], $overrides);
    }

    private function payload(): array
    {
        return ['slot_starts_at' => '2026-07-06T09:00:00+02:00', 'timezone' => 'Europe/Oslo',
            'company_name' => 'Synthetic company', 'contact_name' => 'Synthetic customer', 'contact_email' => 'booking-canary@example.test',
            'contact_phone' => '+4712345678', 'message' => 'Private content canary', 'privacy_acknowledged' => true];
    }

    private function callApi(string $method, string $path, array $data = [], ?string $key = null, bool $public = false, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Accept' => 'application/json'];
        if (! $public) {
            $headers['Authorization'] = 'Bearer '.($token ?? $this->token);
        }
        if ($key) {
            $headers['Idempotency-Key'] = $key;
        }

        $response = $this->json($method, '/api/v1/booking/'.$path, $data, $headers);
        if ($response->status() >= 200 && $response->status() < 300) {
            $this->assertSwaggerResult($method, '/api/v1/booking/'.explode('?', $path)[0], $response->status(), $response->json());
        }

        return $response;
    }

    private function requestRecord(): BookingRequest
    {
        $this->callApi('POST', 'public/services/'.$this->setting->slug.'/requests', $this->payload(), (string) Str::uuid(), true)->assertAccepted();

        return BookingRequest::query()->latest('id')->firstOrFail();
    }

    #[Test]
    public function all_current_read_surfaces_have_successful_safe_json(): void
    {
        $booking = $this->requestRecord();
        foreach (['context', 'context?capability=configuration', 'context?capability=review', 'summary', 'settings',
            'settings/'.$this->setting->slug, 'requests', 'requests/'.$booking->id, 'requests/'.$booking->id.'/events'] as $path) {
            $response = $this->callApi('GET', $path)->assertOk()->assertJsonStructure(['data']);
            $this->assertStringNotContainsString('raw_payload', $response->getContent());
            $this->assertStringNotContainsString('user_agent', $response->getContent());
            $this->assertStringNotContainsString('ip_address', $response->getContent());
        }
        foreach (['public/services', 'public/services/'.$this->setting->slug, 'public/services/'.$this->setting->slug.'/slots?date=2026-07-06'] as $path) {
            $response = $this->callApi('GET', $path, public: true)->assertOk();
            $this->assertStringNotContainsString('booking-canary@example.test', $response->getContent());
            $this->assertStringNotContainsString('Private automatic canary', $response->getContent());
            $this->assertStringNotContainsString('assigned_user_id', $response->getContent());
        }
        $this->callApi('GET', 'summary')->assertJsonPath('data.open_request_count', 1);
        $this->callApi('GET', 'requests/'.$booking->id)->assertJsonPath('data.notifications.0.state', 'submitted');
    }

    #[Test]
    public function setting_create_update_toggle_and_receipt_are_operational_and_replay_safe(): void
    {
        $input = $this->input(['opening_window_start' => '10:00', 'opening_window_end' => '15:00',
            'location' => 'Meeting room', 'instructions' => 'Call first', 'spam_honeypot_field' => 'website_trap']);
        unset($input['booking_mode'], $input['allow_new_clients']);
        $key = (string) Str::uuid();
        $created = $this->callApi('POST', 'settings', $input, $key)->assertCreated();
        $this->callApi('POST', 'settings', $input, $key)->assertCreated()->assertExactJson($created->json());
        $this->assertSame(2, BookingServiceSetting::query()->count());
        $setting = BookingServiceSetting::query()->where('slug', $input['slug'])->firstOrFail();
        $this->callApi('GET', 'commands/'.$created->json('command_id'))->assertOk()->assertJsonPath('data.operation', 'setting.create');
        $input['public_name'] = 'Updated consultation';
        $input['expected_revision'] = $created->json('data.revision');
        $updated = $this->callApi('PUT', 'settings/'.$setting->slug, $input, (string) Str::uuid())->assertOk();
        $this->assertSame('Updated consultation', $setting->fresh()->public_name);
        $this->assertSame('staff_confirmed', $setting->fresh()->booking_mode);
        $this->assertTrue($setting->fresh()->allow_new_clients);
        $this->callApi('POST', 'settings/'.$setting->slug.'/toggle', ['expected_revision' => $updated->json('data.revision')], (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $this->callApi('POST', 'settings/'.$setting->slug.'/toggle', ['expected_revision' => $updated->json('data.revision')], (string) Str::uuid())->assertConflict();
    }

    #[Test]
    public function public_submission_returns_only_a_minimal_encrypted_replayable_acknowledgement(): void
    {
        $key = (string) Str::uuid();
        $url = 'public/services/'.$this->setting->slug.'/requests';
        $first = $this->callApi('POST', $url, $this->payload(), $key, true)->assertAccepted();
        $this->assertSame(['reference', 'message'], array_keys($first->json('data')));
        $this->callApi('POST', $url, $this->payload(), $key, true)->assertAccepted()->assertExactJson($first->json());
        $this->assertSame(1, BookingRequest::query()->count());
        Notification::assertSentOnDemandTimes(BookingRequestReceived::class, 1);
        $raw = DB::table('booking_api_commands')->first();
        $this->assertStringNotContainsString($first->json('data.reference'), $raw->result);
        $this->assertStringNotContainsString('booking-canary@example.test', $raw->result);
        $changed = $this->payload();
        $changed['contact_name'] = 'Changed person';
        $this->callApi('POST', $url, $changed, $key, true)->assertConflict();
        $this->callApi('GET', 'public/requests/'.$first->json('data.reference'), public: true)->assertNotFound();
    }

    #[Test]
    public function confirm_creates_one_linked_event_and_replay_does_not_send_again(): void
    {
        $booking = $this->requestRecord();
        $revision = BookingApiPayload::bookingRequest($booking)['revision'];
        $key = (string) Str::uuid();
        $response = $this->callApi('POST', 'requests/'.$booking->id.'/confirm', ['expected_revision' => $revision], $key)->assertOk();
        $this->callApi('POST', 'requests/'.$booking->id.'/confirm', ['expected_revision' => $revision], $key)->assertOk()->assertExactJson($response->json());
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(1, CalendarEvent::query()->where('source', 'booking')->count());
        $this->assertDatabaseHas('calendar_event_links', ['linkable_id' => $booking->id, 'relation' => 'booking_request']);
        $this->assertSame(1, $booking->events()->where('type', 'confirmed')->count());
        $this->assertSame(2, DB::table('booking_notification_dispatches')->where('state', 'submitted')->count());
        $this->callApi('GET', 'requests/'.$booking->id)->assertJsonPath('data.notifications.1.state', 'submitted');
    }

    #[Test]
    public function decline_records_the_reason_without_a_calendar_event(): void
    {
        $booking = $this->requestRecord();
        $data = ['expected_revision' => BookingApiPayload::bookingRequest($booking)['revision'], 'decline_reason' => 'Please choose another day'];
        $key = (string) Str::uuid();
        $response = $this->callApi('POST', 'requests/'.$booking->id.'/decline', $data, $key)->assertOk()->assertJsonPath('data.status', 'declined');
        $this->callApi('POST', 'requests/'.$booking->id.'/decline', $data, $key)->assertExactJson($response->json());
        $this->assertSame('Please choose another day', $booking->fresh()->decline_reason);
        $this->assertSame(0, CalendarEvent::query()->where('source', 'booking')->count());
        $this->assertSame(1, $booking->events()->where('type', 'declined')->count());
    }

    #[Test]
    public function native_permission_and_scope_are_both_required_and_revocation_applies_on_replay(): void
    {
        $input = $this->input();
        unset($input['booking_mode'], $input['allow_new_clients']);
        $readToken = $this->actor->createToken('read', ['booking.read'])->plainTextToken;
        $this->callApi('POST', 'settings', $input, (string) Str::uuid(), token: $readToken)->assertForbidden();
        $key = (string) Str::uuid();
        $created = $this->callApi('POST', 'settings', $input, $key)->assertCreated();
        $this->actor->revokePermissionTo('booking.manage');
        $this->callApi('POST', 'settings', $input, $key)->assertForbidden();
        $this->callApi('GET', 'commands/'.$created->json('command_id'))->assertForbidden();
        $this->callApi('GET', 'summary')->assertOk();
    }

    #[Test]
    public function configuration_and_review_do_not_require_an_extra_view_permission(): void
    {
        $this->actor->revokePermissionTo('booking.view');
        $this->callApi('GET', 'context?capability=configuration')->assertOk();
        $this->callApi('GET', 'settings/'.$this->setting->slug)->assertOk();
        $this->callApi('GET', 'summary')->assertForbidden();
        $booking = $this->requestRecord();
        $this->callApi('POST', 'requests/'.$booking->id.'/decline', ['expected_revision' => BookingApiPayload::bookingRequest($booking)['revision']], (string) Str::uuid())->assertOk();
    }

    #[Test]
    public function command_results_are_bound_to_the_original_token_and_actor(): void
    {
        $input = $this->input();
        unset($input['booking_mode'], $input['allow_new_clients']);
        $result = $this->callApi('POST', 'settings', $input, (string) Str::uuid())->assertCreated();
        $otherToken = $this->actor->createToken('other', ['*'])->plainTextToken;
        $this->callApi('GET', 'commands/'.$result->json('command_id'), token: $otherToken)->assertNotFound();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->givePermissionTo('booking.manage');
        $this->callApi('GET', 'commands/'.$result->json('command_id'), token: $other->createToken('other', ['*'])->plainTextToken)->assertNotFound();
    }

    #[Test]
    public function inactive_and_unprivileged_accounts_cannot_use_wildcard_tokens(): void
    {
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->callApi('GET', 'summary')->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->callApi('GET', 'summary', token: $other->createToken('none', ['*'])->plainTextToken)->assertForbidden();
    }

    #[Test]
    public function writes_remain_disabled_without_operational_activation(): void
    {
        config(['booking.api_public_submissions_enabled' => false, 'booking.api_staff_writes_enabled' => false]);
        $this->callApi('POST', 'public/services/'.$this->setting->slug.'/requests', $this->payload(), (string) Str::uuid(), true)->assertStatus(503);
        $this->callApi('POST', 'settings/'.$this->setting->slug.'/toggle', ['expected_revision' => BookingApiPayload::setting($this->setting)['revision']], (string) Str::uuid())->assertStatus(503);
        $this->assertSame(0, BookingApiCommand::query()->count());
        $this->callApi('GET', 'public/services', public: true)->assertOk();
        $this->callApi('GET', 'summary')->assertOk();
    }

    #[Test]
    public function unknown_fields_bad_keys_stale_and_out_of_bounds_inputs_have_no_effect(): void
    {
        $url = 'public/services/'.$this->setting->slug.'/requests';
        $this->callApi('POST', $url, $this->payload(), public: true)->assertUnprocessable();
        $this->callApi('POST', $url, $this->payload() + ['status' => 'confirmed'], (string) Str::uuid(), true)->assertUnprocessable();
        $this->callApi('GET', 'requests?per_page=101')->assertUnprocessable();
        $this->callApi('GET', 'public/services/'.$this->setting->slug.'/slots?limit=101', public: true)->assertUnprocessable();
        $data = $this->payload();
        $data['privacy_acknowledged'] = false;
        $this->callApi('POST', $url, $data, (string) Str::uuid(), true)->assertUnprocessable();
        $data = $this->payload();
        $data['slot_starts_at'] = '2026-07-06T09:07:00+02:00';
        $this->callApi('POST', $url, $data, (string) Str::uuid(), true)->assertUnprocessable();
        $this->assertSame(0, BookingRequest::query()->count());
    }

    #[Test]
    public function spam_has_the_same_public_shape_and_no_customer_notification(): void
    {
        $input = $this->payload() + ['booking_website' => 'filled'];
        $response = $this->callApi('POST', 'public/services/'.$this->setting->slug.'/requests', $input, (string) Str::uuid(), true)->assertAccepted();
        $this->assertSame(['reference', 'message'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('spam', $response->getContent());
        Notification::assertNothingSent();
        $this->assertSame('spam', BookingRequest::query()->firstOrFail()->status);
    }

    #[Test]
    public function automatic_mode_hides_identities_and_customer_choice_rejects_other_people(): void
    {
        $this->setting->update(['technician_routing_mode' => 'automatic', 'assigned_user_id' => null]);
        $this->setting->eligibleUsers()->sync([$this->technician->id]);
        $context = $this->callApi('GET', 'public/services/'.$this->setting->slug, public: true)->assertOk()->assertJsonPath('data.technicians', []);
        $this->assertStringNotContainsString($this->technician->name, $context->getContent());
        $this->callApi('GET', 'public/services/'.$this->setting->slug.'/slots?date=2026-07-06', public: true)->assertOk();
        $this->setting->update(['technician_routing_mode' => 'customer_choice']);
        $this->callApi('GET', 'public/services/'.$this->setting->slug, public: true)->assertJsonPath('data.technicians.0.id', $this->technician->id);
        $this->callApi('GET', 'public/services/'.$this->setting->slug.'/slots?technician_id='.$this->actor->id, public: true)->assertUnprocessable();
        $payload = $this->payload();
        $payload['technician_id'] = $this->actor->id;
        $this->callApi('POST', 'public/services/'.$this->setting->slug.'/requests', $payload, (string) Str::uuid(), true)->assertUnprocessable();
    }

    #[Test]
    public function unpublished_and_inactive_services_are_not_public_api_resources(): void
    {
        $this->setting->update(['status' => 'draft']);
        $this->callApi('GET', 'public/services', public: true)->assertJsonPath('data.total', 0);
        $this->callApi('GET', 'public/services/'.$this->setting->slug, public: true)->assertNotFound();
        $this->callApi('GET', 'public/services/'.$this->setting->slug.'/slots', public: true)->assertNotFound();
        $this->callApi('POST', 'public/services/'.$this->setting->slug.'/requests', $this->payload(), (string) Str::uuid(), true)->assertNotFound();
    }

    #[Test]
    public function stale_native_model_cannot_overwrite_a_completed_confirmation(): void
    {
        $booking = $this->requestRecord();
        $stale = $booking->fresh();
        app(ConfirmBookingRequest::class)->handle($booking, $this->actor);
        try {
            app(DeclineBookingRequest::class)->handle($stale, $this->actor, 'stale action');
            $this->fail('Stale native decline must not replace confirmation.');
        } catch (\Illuminate\Validation\ValidationException $error) {
            $this->assertArrayHasKey('status', $error->errors());
        }
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, $booking->events()->where('type', 'declined')->count());
    }

    #[Test]
    public function two_pending_requests_can_share_a_slot_but_only_one_confirmation_succeeds(): void
    {
        $first = $this->requestRecord();
        $second = $this->requestRecord();
        app(ConfirmBookingRequest::class)->handle($first, $this->actor);
        $this->callApi('POST', 'requests/'.$second->id.'/confirm', ['expected_revision' => BookingApiPayload::bookingRequest($second)['revision']], (string) Str::uuid())->assertUnprocessable();
        $this->assertSame('requested', $second->fresh()->status);
        $this->assertSame(1, CalendarEvent::query()->where('source', 'booking')->count());
    }

    #[Test]
    public function ambiguous_notification_failure_is_not_resent_on_command_replay(): void
    {
        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('PRIVATE_PROVIDER_CANARY'));
        $key = (string) Str::uuid();
        $url = 'public/services/'.$this->setting->slug.'/requests';
        $response = $this->callApi('POST', $url, $this->payload(), $key, true)->assertAccepted();
        $this->callApi('POST', $url, $this->payload(), $key, true)->assertAccepted();
        $booking = BookingRequest::query()->firstOrFail();
        $this->assertDatabaseHas('booking_notification_dispatches', ['booking_request_id' => $booking->id, 'state' => 'unknown']);
        $result = $this->callApi('GET', 'requests/'.$booking->id)->assertJsonPath('data.notifications.0.state', 'unknown');
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_CANARY', $result->getContent());
        $this->assertNull($booking->customer_requested_notification_sent_at);
    }

    #[Test]
    public function public_and_browser_submissions_share_a_rate_limit(): void
    {
        $url = 'public/services/'.$this->setting->slug.'/requests';
        for ($i = 0; $i < 10; $i++) {
            $this->callApi('POST', $url, $this->payload() + ['booking_website' => 'filled'], (string) Str::uuid(), true)->assertAccepted();
        }
        $this->post('/booking/'.$this->setting->slug, $this->payload())->assertStatus(429);
        $this->callApi('POST', $url, $this->payload(), (string) Str::uuid(), true)->assertStatus(429);
    }

    #[Test]
    public function local_decision_persists_its_dispatch_claim_before_transport_execution(): void
    {
        $booking = $this->requestRecord();
        Notification::fake();
        app(ConfirmBookingRequest::class)->handle($booking, $this->actor, true);
        Notification::assertNothingSent();
        $this->assertDatabaseHas('booking_notification_dispatches', ['booking_request_id' => $booking->id, 'kind' => 'confirmed', 'state' => 'pending']);
        $this->assertSame('confirmed', $booking->fresh()->status);
        app(\App\Modules\Booking\Actions\NotifyBookingCustomer::class)->dispatch($booking->id);
        $this->assertDatabaseHas('booking_notification_dispatches', ['booking_request_id' => $booking->id, 'kind' => 'confirmed', 'state' => 'submitted']);
    }

    /** Validate successful runtime output against the generated contract, including nested privacy allowlists. */
    private function assertSwaggerResult(string $method, string $path, int $status, mixed $result): void
    {
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        $operation = null;
        foreach ($spec['paths'] as $template => $methods) {
            if (! str_starts_with($template, '/api/v1/booking/')) {
                continue;
            }
            $pattern = '#^'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($template, '#')).'$#';
            if (preg_match($pattern, $path) && isset($methods[strtolower($method)])) {
                $operation = $methods[strtolower($method)];
                break;
            }
        }
        $this->assertNotNull($operation, 'Successful Booking operation must exist in Swagger: '.$method.' '.$path);
        $schema = $operation['responses'][(string) $status]['content']['application/json']['schema'] ?? null;
        $this->assertNotNull($schema, 'Successful Booking result must have a typed schema.');
        $errors = $this->schemaErrors($result, $schema, $spec, '$');
        $this->assertSame([], $errors, implode('; ', $errors));
    }

    private function schemaErrors(mixed $value, array $schema, array $spec, string $path): array
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return [];
        }
        if (isset($schema['$ref'])) {
            return $this->schemaErrors($value, $spec['components']['schemas'][basename($schema['$ref'])], $spec, $path);
        }
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $branch) {
                if ($this->schemaErrors($value, $branch, $spec, $path) === []) {
                    $matches++;
                }
            }

            return $matches === 1 ? [] : [$path.' does not match exactly one result schema'];
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object', 'array' => is_array($value), 'integer' => is_int($value), 'boolean' => is_bool($value),
            'number' => is_numeric($value), 'string' => is_string($value), default => true,
        };
        if (! $valid) {
            return [$path.' is not '.$type];
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            return [$path.' has an unsupported value'];
        }
        $errors = [];
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $value)) {
                    $errors[] = $path.'.'.$key.' is required';
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    $errors = array_merge($errors, $this->schemaErrors($item, $schema['properties'][$key], $spec, $path.'.'.$key));
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$key.' is an undocumented field';
                }
            }
        }
        if ($type === 'array') {
            foreach ($value as $key => $item) {
                $errors = array_merge($errors, $this->schemaErrors($item, $schema['items'], $spec, $path.'['.$key.']'));
            }
        }

        return $errors;
    }
}
