<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\WorkContext\Actions\EnsureWorkContextDefaults;
use App\Modules\Workday\Actions\WorkdayReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Consumer-level HTTP contracts: no gateway, Sanctum actingAs shortcut or shared employee identity. */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private string $token;

    private array $scopes = ['users.work-plan.read', 'users.work-plan.update', 'calendar.work-plan.read', 'calendar.work-plan.write',
        'workdays.read', 'workdays.write', 'workdays.confirm', 'workday-absences.read', 'workday-absences.write',
        'workday-reminders.read', 'workday-reminders.write', 'workday-task-conversion.write', 'tasks.read', 'tasks.create', 'tasks.update'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 16:05', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->orWhere('name', 'like', 'calendar.%')->get());
        $role->givePermissionTo(['task.view', 'task.create', 'task.update']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        app(EnsureCalendarDefaults::class)->handle($this->worker);
        $this->token = $this->worker->createToken('Synthetic employee API client', $this->scopes)->plainTextToken;
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function callApi(string $method, string $path, array $body = [], ?string $key = null, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token)];
        if ($key !== null) {
            $headers['Idempotency-Key'] = $key;
        }

        return $this->json($method, '/api/v1/'.$path, $body, $headers);
    }

    private function day(): array
    {
        return $this->callApi('PUT', 'workdays/2026-10-01/draft', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'description' => 'Synthetic API work', 'intervals' => [['start' => '2026-10-01T08:00', 'end' => '2026-10-01T16:00']],
            'breaks' => []], (string) Str::uuid())->assertOk()->json('data');
    }

    private function confirm(array $day): array
    {
        $preview = $this->callApi('POST', 'workdays/'.$day['id'].'/preview', ['version' => $day['version']], (string) Str::uuid())
            ->assertOk()->json('preview');
        $body = ['version' => $day['version'], 'preview_token' => $preview['token'], 'confirmed' => true];
        $key = (string) Str::uuid();
        $result = $this->callApi('POST', 'workdays/'.$day['id'].'/confirm', $body, $key)->assertOk()->json('data');
        $this->assertSame($result, $this->callApi('POST', 'workdays/'.$day['id'].'/confirm', $body, $key)->assertOk()->json('data'));

        return $result;
    }

    public function test_personal_bearer_plan_absence_day_confirmation_correction_and_reminder_workflow(): void
    {
        $plan = $this->callApi('GET', 'users/me/work-plan')->assertOk()->json('data');
        $planBody = array_intersect_key($plan, array_flip(['revision', 'timezone', 'working_hours']));
        $planBody['timezone'] = 'Europe/Oslo';
        $planBody['working_hours']['thursday'] = ['enabled' => true, 'start' => '08:00', 'end' => '16:00'];
        $plan = $this->callApi('PATCH', 'users/me/work-plan', $planBody)->assertOk()->json('data');
        $this->callApi('GET', 'users/me/work-plan')->assertOk()->assertJsonPath('data.revision', $plan['revision']);
        $this->callApi('PATCH', 'users/me/work-plan', $planBody)->assertConflict();
        $blockBody = ['request_id' => (string) Str::uuid(), 'title' => 'Education', 'activity' => 'education',
            'timezone' => 'Europe/Oslo', 'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T16:00',
            'phone_duty_available' => false, 'blocks_booking' => true, 'recurrence_frequency' => 'weekly', 'recurrence_ends_at' => '2026-12-21'];
        $block = $this->callApi('POST', 'calendar/work-plan/blocks', $blockBody)->assertOk()->json('data');
        $this->callApi('POST', 'calendar/work-plan/blocks', $blockBody)->assertOk()->assertJsonPath('data.id', $block['id']);
        $this->callApi('GET', 'calendar/work-plan/blocks')->assertOk()->assertJsonPath('total', 1);
        $block = $this->callApi('PATCH', 'calendar/work-plan/blocks/'.$block['id'],
            array_replace($blockBody, ['title' => 'Updated education', 'version' => 1, 'scope' => 'series']))->assertOk()->json('data');
        $this->callApi('DELETE', 'calendar/work-plan/blocks/'.$block['id'], ['version' => $block['version'], 'scope' => 'series'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $absenceBody = ['version' => 0, 'timezone' => 'Europe/Oslo', 'mode' => 'full_day', 'category' => 'other',
            'start_date' => '2026-10-02', 'end_date' => '2026-10-02'];
        $a = $this->callApi('POST', 'workday-absences', $absenceBody, (string) Str::uuid())->assertOk()->json('data');
        $this->callApi('GET', 'workday-absences/'.$a['id'])->assertOk()->assertJsonPath('data.id', $a['id']);
        $a = $this->callApi('PATCH', 'workday-absences/'.$a['id'], array_replace($absenceBody, ['version' => $a['version'], 'category' => 'sickness']),
            (string) Str::uuid())->assertOk()->json('data');
        $this->callApi('POST', 'workday-absences/'.$a['id'].'/cancel', ['version' => $a['version']], (string) Str::uuid())->assertOk();
        $this->callApi('GET', 'workday-absences/'.$a['id'].'/history')->assertOk()->assertJsonPath('total', 3);
        $this->callApi('GET', 'workday-absences?status=cancelled')->assertOk()->assertJsonPath('total', 1);

        $preferences = ['database_enabled' => true, 'mail_enabled' => false, 'web_push_enabled' => false];
        $this->callApi('PUT', 'workday-reminder-preferences', $preferences)->assertOk();
        $this->callApi('GET', 'workday-reminder-preferences')->assertOk()->assertJsonPath('data', $preferences);
        app(WorkdayReminders::class)->scan();
        foreach (\App\Modules\Workday\Models\WorkdayReminderDelivery::pluck('id') as $deliveryId) {
            app()->call([new \App\Modules\Workday\Jobs\DeliverWorkdayReminder($deliveryId), 'handle']);
        }
        $reminder = $this->callApi('GET', 'workday-reminders')->assertOk()->json('data.0');
        $this->assertNotNull($reminder);
        $this->callApi('POST', 'workday-reminders/'.$reminder['id'].'/snooze', ['generation' => $reminder['generation']])->assertOk();

        $day = $this->day();
        $this->callApi('GET', 'workdays/'.$day['id'].'/sources?kind=task')->assertOk();
        $day = $this->callApi('PUT', 'workdays/'.$day['id'].'/allocations', ['version' => $day['version'], 'allocations' => []],
            (string) Str::uuid())->assertOk()->json('data');
        $day = $this->confirm($day);
        $this->callApi('GET', 'workdays/'.$day['id'])->assertOk()->assertJsonPath('data.current.state', 'confirmed');
        $this->callApi('GET', 'workday-reminders')->assertOk()->assertJsonCount(0, 'data');
        $oldConfirmation = $day['confirmed'];
        $day = $this->callApi('POST', 'workdays/'.$day['id'].'/corrections', ['version' => $day['version'], 'reason' => 'Synthetic correction'],
            (string) Str::uuid())->assertOk()->json('data');
        $this->assertSame($oldConfirmation, $day['confirmed']);
        $day = $this->confirm($day);
        $this->callApi('GET', 'workdays/'.$day['id'].'/history?per_page=1')->assertOk()
            ->assertJsonPath('data.0.state', 'confirmed')->assertJsonPath('per_page', 1);
        $this->callApi('GET', 'workdays?from=2026-10-01&to=2026-10-01')->assertOk()->assertJsonPath('total', 1);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('ticket_time_entries', 0);
    }

    public function test_personal_bearer_explicit_task_creation_readback_and_separate_oversight(): void
    {
        app(EnsureTaskDefaults::class)->handle();
        app(EnsureWorkContextDefaults::class)->internalContext();
        $day = $this->day();
        $path = 'workdays/'.$day['id'].'/task-conversions';
        $preview = $this->callApi('POST', $path.'/preview', ['version' => $day['version'], 'interval_index' => 0,
            'title' => 'Synthetic internal Task', 'description' => 'API conversion', 'start' => '2026-10-01T08:00', 'end' => '2026-10-01T09:00'],
            (string) Str::uuid())->assertOk()->json('preview');
        $key = (string) Str::uuid();
        $body = ['version' => $day['version'], 'preview_token' => $preview['token'], 'create_task' => true];
        $result = $this->callApi('POST', $path, $body, $key)->assertOk()->json();
        $this->assertSame($result, $this->callApi('POST', $path, $body, $key)->assertOk()->json());
        $this->callApi('GET', $path.'/'.$preview['token'])->assertOk()->assertJsonPath('state', 'created');
        $day = $this->confirm($result['data']);
        $this->callApi('GET', 'workdays/overview')->assertForbidden();
        $oversight = $this->worker->createToken('Synthetic oversight client', ['workdays.read-all', 'workdays.settings'])->plainTextToken;
        $this->callApi('GET', 'workdays/overview?from=2026-10-01&to=2026-10-01', token: $oversight)->assertOk();
        $this->callApi('GET', 'workdays/overview/'.$day['id'], token: $oversight)->assertOk();
        $this->callApi('GET', 'workdays/overview/'.$day['id'].'/history', token: $oversight)->assertOk();
        $settings = $this->callApi('GET', 'workday-settings', token: $oversight)->assertOk()->json('data');
        $this->callApi('PATCH', 'workday-settings', ['version' => $settings['version'], 'enabled' => true],
            (string) Str::uuid(), $oversight)->assertOk();
        $this->callApi('POST', 'workday-settings/retention-preview', token: $oversight)->assertOk();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_time_entries', 1);
        $this->assertDatabaseCount('ticket_time_entries', 0);
    }

    public function test_bound_coordinator_token_cannot_enter_work_plan_operations(): void
    {
        $token = $this->worker->createToken('Synthetic coordinator credential', ['*']);
        $profile = AiWorkloadProfile::create(['name' => 'Synthetic coordinator', 'slug' => 'synthetic-api-coordinator',
            'purpose' => 'Identity isolation', 'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true, 'expires_at' => now()->addMonth(),
            'approved_by' => $this->worker->id, 'approved_at' => now(), 'created_by' => $this->worker->id]);
        AiWorkloadTokenBinding::create(['personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $profile->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30, 'created_by' => $this->worker->id]);
        $this->callApi('GET', 'users/me/work-plan', token: $token->plainTextToken)->assertForbidden();
        $this->callApi('PATCH', 'users/me/work-plan', token: $token->plainTextToken)->assertForbidden();
        $this->callApi('GET', 'calendar/work-plan/blocks', token: $token->plainTextToken)->assertForbidden();
        $this->callApi('POST', 'calendar/work-plan/blocks', token: $token->plainTextToken)->assertForbidden();
        $this->assertSame(0, \App\Modules\UserManagement\Models\UserProfile::count());
    }

    public function test_every_supported_operation_rejects_anonymous_and_unscoped_tokens(): void
    {
        $day = $this->day();
        $block = $this->callApi('POST', 'calendar/work-plan/blocks', ['request_id' => (string) Str::uuid(),
            'title' => 'Synthetic block', 'activity' => 'work', 'timezone' => 'Europe/Oslo',
            'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T16:00',
            'phone_duty_available' => true, 'blocks_booking' => true, 'recurrence_frequency' => 'none'])->assertOk()->json('data');
        $emptyToken = $this->worker->createToken('Synthetic token with no abilities', [])->plainTextToken;
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            $path = '/'.$route->uri();
            if (! preg_match('~^/api/v1/(workdays(?:/|$)|workday-|users/me/work-plan$|calendar/work-plan/)~', $path)) {
                continue;
            }
            $path = str_replace(['{event}', '{work_date}', '{id}', '{token}'], [$block['id'], '2026-10-01', $day['id'], (string) Str::uuid()], $path);
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $this->app['auth']->forgetGuards();
                $this->json($method, $path)->assertUnauthorized();
                $this->callApi($method, substr($path, strlen('/api/v1/')), token: $emptyToken)->assertForbidden();
                $count++;
            }
        }
        $this->assertSame(36, $count);
        $this->assertDatabaseCount('workdays', 1);
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->assertDatabaseCount('calendar_events', 1);
    }

    public function test_every_supported_operation_is_published_with_exact_scopes_and_a_success_contract(): void
    {
        Artisan::call('l5-swagger:generate');
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $covered = [];
        foreach (Route::getRoutes() as $route) {
            $path = '/'.$route->uri();
            if (! preg_match('~^/api/v1/(workdays(?:/|$)|workday-|users/me/work-plan$|calendar/work-plan/)~', $path)) {
                continue;
            }
            $scopes = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_starts_with($middleware, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':')) {
                    $scopes = explode(',', explode(':', $middleware, 2)[1]);
                }
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $verb) {
                $method = strtolower($verb);
                $operation = $spec['paths'][$path][$method] ?? null;
                $this->assertNotNull($operation, $method.' '.$path);
                $this->assertNotEmpty($scopes, $path);
                $this->assertSame($scopes, $operation['x-required-scopes'] ?? null, $method.' '.$path);
                $this->assertSame([['bearerAuth' => []]], $operation['security'], $path);
                $this->assertNotEmpty($operation['responses']['200']['content']['application/json']['schema'] ?? null, $path);
                $covered[] = $operation['operationId'];
            }
        }
        $this->assertCount(36, $covered);
        $this->assertCount(36, array_unique($covered));
        $this->assertArrayHasKey('next_page_url', $spec['components']['schemas']['WorkPlanBlockList']['properties']);
        $this->assertSame(false, $spec['components']['schemas']['WorkPlanWrite']['additionalProperties']);
    }
}
