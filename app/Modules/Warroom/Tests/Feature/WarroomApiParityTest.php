<?php

namespace App\Modules\Warroom\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Warroom\Actions\SaveWarroomSettings;
use App\Modules\Warroom\Support\WarroomSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer requests verify actual persisted effects and forbidden-data boundaries. */
class WarroomApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-27 10:00:00', 'Europe/Oslo'));
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo(['warroom.view', 'warroom.manage_settings', 'ticket.view', 'task.view',
            'calendar.view', 'asset.view', 'integration.view', 'email.inbox_view']);
        $this->token = $this->tech->createToken('Warroom test', ['warroom.read', 'warroom.settings.read', 'warroom.settings.manage'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function requestApi(string $method, string $path, array $data = [], array $headers = [], ?string $token = null)
    {
        app('auth')->forgetGuards();

        return $this->json($method, '/api/v1/warroom'.$path, $data,
            ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json'] + $headers);
    }

    #[Test]
    public function every_operation_returns_a_typed_result_and_settings_replay_is_exact_and_encrypted(): void
    {
        $this->requestApi('GET', '/context')->assertOk()->assertJsonPath('data.preview_limits.tickets', 8);
        $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.tickets', 'available');
        $this->requestApi('GET', '/my-day')->assertOk()->assertJsonPath('data.counts.tickets', 0);
        $this->requestApi('GET', '/my-day/overdue')->assertOk()->assertJsonPath('data.total', 0);
        $snapshot = $this->requestApi('GET', '/settings')->assertOk();
        $payload = $snapshot->json('data.settings');
        $payload['due_soon_hours'] = 12;
        $headers = ['If-Match' => $snapshot->json('data.revision'), 'Idempotency-Key' => 'warroom-settings-one'];
        $write = $this->requestApi('PUT', '/settings', $payload, $headers)->assertOk()
            ->assertJsonPath('data.settings.due_soon_hours', 12)->assertJsonPath('data.replayed', false);
        $this->assertSame($write->headers->get('ETag'), $write->json('data.revision'));
        $replay = $this->requestApi('PUT', '/settings', $payload, $headers)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($write->json('data.operation_id'), $replay->json('data.operation_id'));
        $this->assertSame(1, DB::table('warroom_api_commands')->count());
        $this->assertSame(12, app(WarroomSettings::class)->get()['due_soon_hours']);
        $this->assertStringNotContainsString('due_soon_hours', DB::table('warroom_api_commands')->value('result'));
        $this->requestApi('GET', '/operations/'.$write->json('data.operation_id'))->assertOk()
            ->assertJsonPath('data.state', 'completed')->assertJsonPath('data.result.settings.due_soon_hours', 12);
    }

    #[Test]
    public function tokens_do_not_grant_native_rights_and_revoked_or_inactive_actors_are_denied(): void
    {
        $narrow = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $narrow->assignRole('Tech');
        $token = $narrow->createToken('Full token only', ['*'])->plainTextToken;
        $this->requestApi('GET', '/dashboard', token: $token)->assertForbidden();
        $this->tech->revokePermissionTo('warroom.view');
        $this->requestApi('GET', '/my-day')->assertForbidden();
        $this->tech->givePermissionTo('warroom.view');
        $readOnly = $this->tech->createToken('Read only', ['warroom.read'])->plainTextToken;
        $this->requestApi('GET', '/settings', token: $readOnly)->assertForbidden();
        $this->tech->update(['status' => 'inactive']);
        $this->requestApi('GET', '/context')->assertForbidden();
        $this->tech->update(['status' => User::STATUS_ACTIVE, 'is_system_actor' => true]);
        $this->requestApi('GET', '/context')->assertForbidden();
    }

    #[Test]
    public function a_settings_only_native_manager_does_not_need_dashboard_access(): void
    {
        $this->tech->revokePermissionTo('warroom.view');
        $snapshot = $this->requestApi('GET', '/settings')->assertOk();
        $this->requestApi('PUT', '/settings', $snapshot->json('data.settings'),
            ['If-Match' => $snapshot->json('data.revision'), 'Idempotency-Key' => 'settings-only-manager'])->assertOk();
        $this->requestApi('GET', '/context')->assertForbidden();
    }

    #[Test]
    public function missing_keys_stale_state_unknown_fields_and_changed_retry_bodies_never_mutate_twice(): void
    {
        $snapshot = $this->requestApi('GET', '/settings')->assertOk();
        $payload = $snapshot->json('data.settings');
        $revision = $snapshot->json('data.revision');
        $this->requestApi('PUT', '/settings', $payload)->assertStatus(428);
        $this->requestApi('PUT', '/settings', $payload, ['If-Match' => $revision])->assertStatus(428);
        $this->requestApi('PUT', '/settings', $payload + ['actor_id' => 1])->assertStatus(422);
        $invalid = $payload;
        $invalid['enabled_sections'] = ['pulse', 'pulse'];
        $this->requestApi('PUT', '/settings', $invalid, ['If-Match' => $revision, 'Idempotency-Key' => 'invalid-settings'])->assertUnprocessable();
        $payload['due_soon_hours'] = 18;
        $headers = ['If-Match' => $revision, 'Idempotency-Key' => 'first-settings-save'];
        $this->requestApi('PUT', '/settings', $payload, $headers)->assertOk();
        $payload['due_soon_hours'] = 20;
        $this->requestApi('PUT', '/settings', $payload, $headers)->assertConflict();
        $this->requestApi('PUT', '/settings', $payload, ['If-Match' => $revision, 'Idempotency-Key' => 'second-settings-save'])->assertStatus(412);
        $this->assertSame(18, app(WarroomSettings::class)->get()['due_soon_hours']);
        $this->assertSame(1, DB::table('warroom_api_commands')->count());
        $this->requestApi('GET', '/my-day?user_id=9')->assertUnprocessable();
        $this->requestApi('GET', '/my-day?per_page=101&focus=overdue')->assertUnprocessable();
        $this->requestApi('GET', '/my-day?per_page=3')->assertUnprocessable();
        $this->requestApi('GET', '/dashboard', ['focus' => 'overdue'])->assertUnprocessable();
    }

    #[Test]
    public function receipt_lookup_and_replay_require_the_original_token_and_current_native_right(): void
    {
        $snapshot = $this->requestApi('GET', '/settings')->assertOk();
        $headers = ['If-Match' => $snapshot->json('data.revision'), 'Idempotency-Key' => 'revoked-settings-key'];
        $payload = $snapshot->json('data.settings');
        $saved = $this->requestApi('PUT', '/settings', $payload, $headers)->assertOk();
        $path = '/operations/'.$saved->json('data.operation_id');
        $other = $this->tech->createToken('Another token', ['warroom.settings.manage'])->plainTextToken;
        $this->requestApi('GET', $path, token: $other)->assertNotFound();
        $stranger = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $stranger->givePermissionTo('warroom.manage_settings');
        $this->requestApi('GET', $path, token: $stranger->createToken('Stranger', ['warroom.settings.manage'])->plainTextToken)->assertNotFound();
        $this->tech->revokePermissionTo('warroom.manage_settings');
        $this->requestApi('GET', $path)->assertForbidden();
        $this->requestApi('PUT', '/settings', $payload, $headers)->assertForbidden();
    }

    #[Test]
    public function warroom_only_access_never_discloses_cross_domain_counts_rows_or_links(): void
    {
        Ticket::factory()->create(['subject' => 'PRIVATE-TICKET-CANARY', 'owner_id' => $this->tech->id]);
        $this->tech->syncPermissions(['warroom.view']);
        $dashboard = $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.tickets', 'inaccessible')
            ->assertJsonCount(0, 'data.latest_tickets')->assertJsonCount(0, 'data.pulse')->assertJsonCount(0, 'data.operations');
        $this->assertStringNotContainsString('PRIVATE-TICKET-CANARY', $dashboard->getContent());
        $this->assertSame([], $dashboard->json('data.system'));
        $this->assertSame(null, $dashboard->json('data.storage_should_order.count'));
        $day = $this->requestApi('GET', '/my-day')->assertOk()->assertJsonPath('data.counts.tickets', null)
            ->assertJsonCount(0, 'data.tickets')->assertJsonCount(0, 'data.actions');
        $this->assertStringNotContainsString('PRIVATE-TICKET-CANARY', $day->getContent());
        $this->actingAs($this->tech)->get(route('tech.dashboard'))->assertOk()->assertDontSee('PRIVATE-TICKET-CANARY')->assertDontSee('Ticket Fireline');
        $this->actingAs($this->tech)->get(route('tech.my-day.index'))->assertOk()->assertDontSee('PRIVATE-TICKET-CANARY')->assertDontSee('Work items');
    }

    #[Test]
    public function personal_totals_precede_caps_and_overdue_pagination_matches_the_same_scope(): void
    {
        $status = TaskStatus::create(['name' => 'Open', 'slug' => 'warroom-open', 'is_open' => true, 'is_active' => true]);
        for ($i = 1; $i <= 11; $i++) {
            Ticket::factory()->create(['owner_id' => $this->tech->id, 'subject' => 'My ticket '.$i, 'resolve_due_at' => now()->subHours(2)]);
            Task::create(['title' => 'My task '.$i, 'owner_type' => User::class, 'owner_id' => $this->tech->id,
                'created_by' => $this->tech->id, 'assigned_to' => $this->tech->id, 'status_id' => $status->id, 'due_at' => now()->subHour()]);
        }
        Ticket::factory()->create(['subject' => 'OTHER-OWNER-CANARY', 'resolve_due_at' => now()->subDay()]);
        Ticket::factory()->create(['owner_id' => $this->tech->id, 'closed_at' => now(), 'resolve_due_at' => now()->subDay()]);
        $day = $this->requestApi('GET', '/my-day?focus=overdue&per_page=5')->assertOk()->assertJsonPath('data.counts.tickets', 11)
            ->assertJsonPath('data.counts.tasks', 11)->assertJsonPath('data.counts.overdue', 22)
            ->assertJsonCount(8, 'data.tickets')->assertJsonCount(8, 'data.tasks')->assertJsonCount(5, 'data.overdue.items');
        $page1 = $this->requestApi('GET', '/my-day/overdue?per_page=5')->assertOk()->assertJsonPath('data.total', 22);
        $this->assertSame($day->json('data.overdue'), $page1->json('data'));
        $page2 = $this->requestApi('GET', '/my-day/overdue?per_page=5&page=2')->assertOk();
        $this->assertNotSame($page1->json('data.items'), $page2->json('data.items'));
        $this->assertStringNotContainsString('OTHER-OWNER-CANARY', $day->getContent());
    }

    #[Test]
    public function calendar_detail_masking_and_calendar_membership_are_shared_by_dashboard_and_my_day(): void
    {
        $shared = $this->calendar(true);
        $private = $this->calendar(false);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->event($shared, $other, 'PRIVATE-EVENT-CANARY', 'private');
        $this->event($shared, $this->tech, 'My own visit', 'public');
        $this->event($private, $this->tech, 'HIDDEN-CALENDAR-CANARY', 'public');
        $dashboard = $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.calendar', 'available');
        $this->assertStringNotContainsString('PRIVATE-EVENT-CANARY', $dashboard->getContent());
        $this->assertStringNotContainsString('HIDDEN-CALENDAR-CANARY', $dashboard->getContent());
        $this->assertStringContainsString('Busy', $dashboard->getContent());
        $this->requestApi('GET', '/my-day')->assertOk()->assertJsonPath('data.counts.events', 1)
            ->assertJsonPath('data.events.0.title', 'My own visit');
        $this->actingAs($this->tech)->get(route('tech.dashboard'))->assertOk()->assertDontSee('PRIVATE-EVENT-CANARY')->assertSee('Busy');
    }

    #[Test]
    public function mailbox_metrics_use_read_only_current_content_scope_and_never_return_provider_errors(): void
    {
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach ([$this->tech, $other] as $owner) {
            $account = EmailAccount::create(['address' => 'owner'.$owner->id.'@example.test',
                'account_kind' => 'personal', 'owner_id' => $owner->id, 'is_active' => true]);
            $folder = EmailFolder::create(['account_id' => $account->id, 'path' => 'INBOX', 'name' => 'Inbox',
                'role' => 'inbox', 'is_selectable' => true, 'uid_validity' => 1]);
            $message = EmailMessage::create(['account_id' => $account->id, 'mailbox' => 'INBOX', 'imap_uid' => 1,
                'message_id' => '<'.$owner->id.'@example.test>', 'received_at' => now(), 'subject' => 'MAIL-CANARY',
                'body_text' => 'PRIVATE-BODY-CANARY', 'state' => 'untriaged']);
            EmailMailboxPlacement::create(['email_message_id' => $message->id, 'account_id' => $account->id,
                'email_folder_id' => $folder->id, 'folder_path' => 'INBOX', 'imap_uid_validity' => 1,
                'imap_uid' => 1, 'local_state' => 'active', 'sync_status' => 'synced']);
        }
        // An emergency grant requires Mail's audit/use guard and must not widen a routine KPI.
        $this->tech->givePermissionTo('email.break_glass_activate');
        \App\Modules\Email\Models\EmailBreakGlassAccess::create([
            'email_account_id' => EmailAccount::where('owner_id', $other->id)->value('id'),
            'actor_id' => $this->tech->id, 'can_view_content' => true, 'reason' => 'Synthetic guarded-mail regression',
            'starts_at' => now()->subMinute(), 'expires_at' => now()->addMinutes(10),
        ]);
        DB::table('integrations')->insert(['id' => (string) Str::uuid(), 'name' => 'Test provider', 'type' => 'api',
            'last_error' => 'SECRET-PROVIDER-CANARY', 'is_healthy' => false]);
        $before = $this->databaseFingerprint();
        $response = $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.mail', 'available');
        $mail = collect($response->json('data.pulse'))->firstWhere('label', 'Mail triage');
        $this->assertSame(1, $mail['value']);
        $this->assertStringNotContainsString('PRIVATE-BODY-CANARY', $response->getContent());
        $this->assertStringNotContainsString('SECRET-PROVIDER-CANARY', $response->getContent());
        $this->assertSame('attention', $response->json('data.recent_integrations.0.health'));
        $this->assertSame($before, $this->databaseFingerprint());
    }

    #[Test]
    public function hidden_and_unavailable_sources_are_distinct_from_authorized_empty_results(): void
    {
        $settings = app(WarroomSettings::class)->get();
        $settings['enabled_sections'] = ['pulse'];
        app(WarroomSettings::class)->update($settings);
        $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sections.tickets', 'hidden')
            ->assertJsonPath('data.sources.tickets', 'available')->assertJsonCount(0, 'data.latest_tickets');
        Schema::rename('asset_alerts', 'temporarily_unavailable_asset_alerts');
        try {
            $response = $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.assets', 'unavailable');
            $this->assertNull(collect($response->json('data.pulse'))->firstWhere('label', 'Asset alerts'));
            $this->actingAs($this->tech)->get(route('tech.dashboard'))->assertOk()->assertSee('Assets data is unavailable.');
        } finally {
            Schema::rename('temporarily_unavailable_asset_alerts', 'asset_alerts');
        }
        Schema::rename('email_account_user_grants', 'temporarily_unavailable_mail_grants');
        try {
            $response = $this->requestApi('GET', '/dashboard')->assertOk()->assertJsonPath('data.sources.mail', 'unavailable');
            $this->assertNull(collect($response->json('data.pulse'))->firstWhere('label', 'Mail triage'));
        } finally {
            Schema::rename('temporarily_unavailable_mail_grants', 'email_account_user_grants');
        }
    }

    #[Test]
    public function browser_revision_cannot_overwrite_a_newer_api_save(): void
    {
        $revision = app(SaveWarroomSettings::class)->snapshot()['revision'];
        $payload = app(WarroomSettings::class)->get();
        $this->actingAs($this->tech)->get(route('tech.admin.settings.warroom'))->assertOk()->assertSee('name="revision"', false);
        $payload['due_soon_hours'] = 12;
        $this->requestApi('PUT', '/settings', $payload, ['If-Match' => $revision, 'Idempotency-Key' => 'api-before-browser'])->assertOk();
        $payload['due_soon_hours'] = 22;
        $this->actingAs($this->tech)->put(route('tech.admin.settings.warroom.update'), $payload + ['revision' => $revision])->assertStatus(412);
        $this->assertSame(12, app(WarroomSettings::class)->get()['due_soon_hours']);
    }

    #[Test]
    public function transaction_failure_rolls_back_settings_generation_and_receipt(): void
    {
        $snapshot = $this->requestApi('GET', '/settings')->assertOk();
        $payload = $snapshot->json('data.settings');
        $payload['due_soon_hours'] = 77;
        DB::unprepared("CREATE TRIGGER deny_warroom_receipt BEFORE INSERT ON warroom_api_commands BEGIN SELECT RAISE(ABORT, 'Synthetic receipt failure'); END");
        $this->requestApi('PUT', '/settings', $payload, ['If-Match' => $snapshot->json('data.revision'),
            'Idempotency-Key' => 'atomic-rollback-key'])->assertStatus(503);
        $this->assertNotSame(77, app(WarroomSettings::class)->get()['due_soon_hours']);
        $this->assertSame($snapshot->json('data.revision'), app(SaveWarroomSettings::class)->snapshot()['revision']);
        $this->assertSame(0, DB::table('warroom_api_commands')->count());
    }

    private function calendar(bool $visible): Calendar
    {
        return Calendar::create(['uuid' => (string) Str::uuid(), 'name' => 'Calendar', 'slug' => (string) Str::uuid(),
            'type' => 'shared', 'timezone' => 'Europe/Oslo', 'is_active' => true, 'is_visible_by_default' => $visible,
            'visibility_default' => 'default', 'transparency_default' => 'busy']);
    }

    private function event(Calendar $calendar, User $creator, string $title, string $visibility): CalendarEvent
    {
        return CalendarEvent::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id,
            'title' => $title, 'starts_at' => now()->addHour()->utc(), 'ends_at' => now()->addHours(2)->utc(),
            'timezone' => 'Europe/Oslo', 'status' => 'confirmed', 'transparency' => 'busy',
            'visibility' => $visibility, 'created_by' => $creator->id]);
    }

    /** Compare contents of every user table, not just record totals, to prove GET purity. */
    private function databaseFingerprint(): array
    {
        $data = [];
        foreach (Schema::getTableListing() as $table) {
            if (str_ends_with($table, 'sqlite_sequence')) {
                continue;
            }
            $rows = DB::table($table)->get()->map(function ($row) use ($table) {
                $value = (array) $row;
                if (str_ends_with($table, 'personal_access_tokens')) {
                    unset($value['last_used_at'], $value['updated_at']);
                }

                return $value;
            })->all();
            $data[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $data;
    }

    #[Test]
    public function missing_expired_revoked_and_portal_only_bearers_are_denied(): void
    {
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/warroom/context')->assertUnauthorized();
        $expired = $this->tech->createToken('Expired', ['warroom.read'], now()->subMinute())->plainTextToken;
        $this->requestApi('GET', '/context', token: $expired)->assertUnauthorized();
        $portalOnly = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->requestApi('GET', '/context', token: $portalOnly->createToken('Portal only', ['*'])->plainTextToken)->assertForbidden();
        $this->tech->tokens()->delete();
        $this->requestApi('GET', '/context')->assertUnauthorized();
    }

    #[Test]
    public function enabled_workday_shortcuts_are_discoverable_with_the_same_own_permissions(): void
    {
        $this->tech->givePermissionTo(['workday.view_own', 'workday.absence_view_own']);
        $this->instance(\App\Modules\Workday\Support\WorkdaySettings::class, new class extends \App\Modules\Workday\Support\WorkdaySettings
        {
            public function enabled(): bool
            {
                return true;
            }
        });
        $context = $this->requestApi('GET', '/context')->assertOk();
        $this->assertContains('My workdays', array_column($context->json('data.actions'), 'label'));
        $this->assertContains('My absences', array_column($context->json('data.actions'), 'label'));
        $this->tech->revokePermissionTo('workday.absence_view_own');
        $day = $this->requestApi('GET', '/my-day')->assertOk();
        $this->assertNotContains('My absences', array_column($day->json('data.actions'), 'label'));
        $this->assertContains('My workdays', array_column($day->json('data.actions'), 'label'));
    }

    #[Test]
    public function missing_settings_tables_return_controlled_service_unavailability(): void
    {
        Schema::rename('warroom_api_commands', 'temporarily_unavailable_warroom_commands');
        try {
            $this->requestApi('GET', '/operations/'.Str::uuid())->assertStatus(503);
        } finally {
            Schema::rename('temporarily_unavailable_warroom_commands', 'warroom_api_commands');
        }
        Schema::rename('warroom_api_mutex', 'temporarily_unavailable_warroom_mutex');
        try {
            $this->requestApi('GET', '/settings')->assertStatus(503);
        } finally {
            Schema::rename('temporarily_unavailable_warroom_mutex', 'warroom_api_mutex');
        }
    }

    private static array $observed = [];

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if (! str_starts_with($uri, '/api/v1/warroom')) {
            return $response;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertIsArray($operation);
        $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
        $this->assertIsArray($schema, $method.' '.$route->uri().' status '.$response->status());
        $errors = [];
        $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
            $schema, $spec['components']['schemas'], '$', $errors);
        $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        if ($response->status() === 200) {
            self::$observed[strtolower($method).' /'.$route->uri()] = 200;
        }

        return $response;
    }

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('WARROOM_API_EVIDENCE_PATH')) {
            file_put_contents($path, json_encode(self::$observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        parent::tearDownAfterClass();
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = $path.' below minimum';
            }
            if (isset($schema['maximum']) && $value > $schema['maximum']) {
                $errors[] = $path.' above maximum';
            }
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }
}
