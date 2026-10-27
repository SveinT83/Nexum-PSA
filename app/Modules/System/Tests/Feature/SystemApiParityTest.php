<?php

namespace App\Modules\System\Tests\Feature;

use App\Models\Core\User;
use App\Modules\System\Actions\QueueMaintenanceAction;
use App\Modules\System\Models\SystemApiCommand;
use App\Modules\System\Support\CompanyProfileRules;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Positive parity plus native/token authority, durable effects and exact result regressions. */
class SystemApiParityTest extends TestCase
{
    use \App\Modules\System\Tests\Support\ValidatesSystemApiResponses;
    use RefreshDatabase;

    private User $actor;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'database', 'system_api.maintenance_enabled' => false]);
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['system.view', 'system.settings_manage', 'system.queue_manage']);
        $this->token = $this->actor->createToken('System isolated test', ['*'])->plainTextToken;
        Http::preventStrayRequests();
    }

    private function api(string $method, string $path, array $data = [], array $headers = [], array $files = [])
    {
        $this->app['auth']->forgetGuards();
        $server = ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token];
        foreach ($headers as $key => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
        }
        if ($files) {
            $response = $this->call($method, '/api/v1/system'.$path, $data, [], $files, $server);
            $this->validateSystemResponse($method, $path, $response);

            return $response;
        }
        $server['CONTENT_TYPE'] = 'application/json';

        $response = $this->call($method, '/api/v1/system'.$path, [], [], [], $server, json_encode((object) $data));
        $this->validateSystemResponse($method, $path, $response);

        return $response;
    }

    private function writeHeaders(?string $version = null, ?string $key = null): array
    {
        return ['Idempotency-Key' => $key ?? (string) Str::uuid(),
            'If-Match' => $version ?? app(CompanyProfileSettings::class)->version()];
    }

    private function brandingPayload(): array
    {
        return array_intersect_key(app(CompanyProfileSettings::class)->get(), CompanyProfileRules::branding());
    }

    private function failed(string $queue = 'synthetic-system'): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database',
            'queue' => $queue, 'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'Synthetic',
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []]),
            'exception' => 'SECRET-CANARY raw failure', 'failed_at' => now()]);

        return $uuid;
    }

    #[Test]
    public function settings_only_and_queue_only_actors_have_positive_access_without_system_view(): void
    {
        $this->actor->revokePermissionTo('system.view');
        $this->api('GET', '/company-profile')->assertOk()->assertJsonPath('data.company_name', 'Nexum PSA');
        $this->api('GET', '/branding')->assertOk()->assertJsonPath('data.default_theme', 'light');
        $this->api('GET', '/queues-workers')->assertOk()->assertJsonPath('data.worker_health', 'unknown');
        $this->api('GET', '/context')->assertForbidden();
    }

    #[Test]
    public function native_permissions_and_token_scopes_are_independent_and_rechecked(): void
    {
        $this->token = $this->actor->createToken('Read only', ['system.read'])->plainTextToken;
        $this->api('GET', '/context')->assertOk();
        $this->api('GET', '/branding')->assertForbidden();
        $this->token = $this->actor->createToken('Wildcard', ['*'])->plainTextToken;
        $this->actor->revokePermissionTo('system.settings_manage');
        $this->api('GET', '/branding')->assertForbidden();
        $this->api('PUT', '/company-profile', ['company_name' => 'Denied'], $this->writeHeaders())->assertForbidden();
        $this->assertDatabaseCount('system_api_commands', 0);
    }

    #[Test]
    public function inactive_expired_revoked_and_noninternal_actors_are_rejected(): void
    {
        $created = $this->actor->createToken('Expired', ['*'], now()->subMinute());
        $this->token = $created->plainTextToken;
        $this->api('GET', '/context')->assertUnauthorized();
        $created = $this->actor->createToken('Revoked', ['*']);
        $this->token = $created->plainTextToken;
        $created->accessToken->delete();
        $this->api('GET', '/context')->assertUnauthorized();
        $this->token = $this->actor->createToken('Inactive', ['*'])->plainTextToken;
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->api('GET', '/context')->assertForbidden();
        $this->actor->update(['status' => User::STATUS_ACTIVE]);
        $this->actor->syncPermissions([]);
        $this->api('GET', '/context')->assertForbidden();
    }

    #[Test]
    public function all_company_fields_persist_and_exact_result_replays_without_overwriting_later_state(): void
    {
        $payload = ['company_name' => 'Synthetic System', 'legal_name' => 'Synthetic AS',
            'organization_number' => '123456789', 'address_line_1' => 'One', 'address_line_2' => 'Two',
            'postal_code' => '7710', 'city' => 'Steinkjer', 'country' => 'Norway',
            'support_email' => 'system@example.test', 'phone' => '+4700000000', 'website' => 'https://example.test'];
        $headers = $this->writeHeaders();
        $result = $this->api('PUT', '/company-profile', $payload, $headers)->assertOk()->assertJsonPath('replayed', false);
        $this->assertSame($payload, $result->json('data'));
        $this->api('GET', '/company-profile')->assertOk()->assertJsonPath('data', $payload);
        $etag = $result->headers->get('ETag');
        $this->assertNotSame($headers['If-Match'], $etag);
        $this->api('PUT', '/company-profile', ['company_name' => 'Later'], $this->writeHeaders($etag))->assertOk();
        $this->api('PUT', '/company-profile', $payload, $headers)->assertOk()
            ->assertJsonPath('operation_id', $result->json('operation_id'))->assertJsonPath('replayed', true)
            ->assertJsonPath('data.company_name', 'Synthetic System');
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'Later');
        $this->assertDatabaseCount('system_api_commands', 2);
    }

    #[Test]
    public function stale_missing_headers_changed_key_and_unknown_or_cross_surface_fields_have_no_effects(): void
    {
        $this->api('PUT', '/company-profile', ['company_name' => 'Missing'])->assertStatus(428);
        $this->api('PUT', '/company-profile', ['company_name' => 'Missing'], ['Idempotency-Key' => 'missing-state'])->assertStatus(428);
        $this->api('PUT', '/company-profile', ['company_name' => 'Stale'], $this->writeHeaders('"stale"'))->assertStatus(412);
        $headers = $this->writeHeaders();
        $this->api('PUT', '/company-profile', ['company_name' => 'First'], $headers)->assertOk();
        $this->api('PUT', '/company-profile', ['company_name' => 'Other'], $headers)->assertStatus(409);
        $this->api('PUT', '/company-profile', ['company_name' => 'Bad', 'primary_color' => '#123456'], $this->writeHeaders())->assertUnprocessable();
        $this->api('PUT', '/branding', ['company_name' => 'Mass assignment'] + $this->brandingPayload(), $this->writeHeaders())->assertUnprocessable();
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'First');
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function every_visual_field_and_unsaved_preview_preserves_profile_and_files(): void
    {
        Storage::fake('public');
        $payload = $this->brandingPayload();
        foreach ($payload as $key => &$value) {
            $value = $key === 'default_theme' ? 'system' : '#102030';
        }
        unset($value);
        $this->api('POST', '/branding/preview', $payload)->assertOk()
            ->assertJsonPath('persisted', false)->assertJsonPath('data.primary_color', '#102030');
        $this->api('GET', '/branding')->assertJsonPath('data.primary_color', '#FF6D1F');
        $this->assertDatabaseCount('system_api_commands', 0);
        $response = $this->api('PUT', '/branding', $payload, $this->writeHeaders())->assertOk();
        foreach ($payload as $key => $value) {
            $response->assertJsonPath('data.'.$key, $value);
        }
        $this->api('GET', '/branding')->assertJsonPath('data.default_theme', 'system');
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'Nexum PSA');
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function all_logo_slots_support_multipart_exact_bytes_replay_fallback_and_removal(): void
    {
        Storage::fake('public');
        $files = ['logo' => UploadedFile::fake()->image('fallback.png', 50, 20),
            'logo_light' => UploadedFile::fake()->image('light.png', 60, 20),
            'logo_dark' => UploadedFile::fake()->image('dark.png', 70, 20)];
        $headers = $this->writeHeaders();
        $result = $this->api('POST', '/branding', $this->brandingPayload(), $headers, $files)
            ->assertOk()->assertJsonPath('data.logos.light.configured', true);
        $paths = Storage::disk('public')->allFiles();
        $this->assertCount(3, $paths);
        $this->assertStringNotContainsString('company-branding/', json_encode(array_keys($result->json('data'))));
        foreach (['fallback' => 'logo', 'light' => 'logo_light', 'dark' => 'logo_dark'] as $slot => $key) {
            $this->assertSame(hash_file('sha256', $files[$key]->getRealPath()), $result->json('data.logos.'.$slot.'.sha256'));
        }
        $this->api('POST', '/branding', $this->brandingPayload(), $headers, $files)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertCount(3, Storage::disk('public')->allFiles());
        $this->api('POST', '/branding/preview', ['remove_light_logo' => true])
            ->assertOk()->assertJsonPath('data.logos.light.configured', false);
        $this->api('GET', '/branding')->assertJsonPath('data.logos.light.configured', true);
        $this->api('PUT', '/branding', $this->brandingPayload() + ['remove_light_logo' => true], $this->writeHeaders())
            ->assertOk()->assertJsonPath('data.logos.light.configured', false);
        $settings = app(CompanyProfileSettings::class)->get();
        $this->assertSame($settings['logo_url'], $settings['logo_light_url']);
    }

    #[Test]
    public function presets_and_reset_have_the_same_current_browser_preservation_rules(): void
    {
        $this->api('PUT', '/company-profile', ['company_name' => 'Preserve'], $this->writeHeaders())->assertOk();
        $this->api('GET', '/branding/presets')->assertOk()->assertJsonPath('data.1.key', 'tronder-data');
        $this->api('POST', '/branding/preset', ['preset' => 'tronder-data'], $this->writeHeaders())
            ->assertOk()->assertJsonPath('data.primary_color', '#99B885');
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'Preserve');
        $this->api('PUT', '/branding/reset', [], $this->writeHeaders())
            ->assertOk()->assertJsonPath('data.primary_color', '#FF6D1F');
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'Preserve');
        $this->api('POST', '/branding/preset', ['preset' => 'unknown'], $this->writeHeaders())->assertUnprocessable();
    }

    #[Test]
    public function settings_receipts_are_encrypted_actor_token_bound_and_require_original_live_authority(): void
    {
        $response = $this->api('PUT', '/company-profile', ['company_name' => 'Receipt canary'], $this->writeHeaders())->assertOk();
        $id = $response->json('operation_id');
        $this->assertStringNotContainsString('Receipt canary', DB::table('system_api_commands')->where('id', $id)->value('result'));
        $this->api('GET', '/operations/'.$id)->assertOk()->assertJsonPath('data.result.data.company_name', 'Receipt canary');
        $this->api('GET', '/operations')->assertOk()->assertJsonPath('meta.total', 1);
        $old = $this->token;
        $this->token = $this->actor->createToken('Different token', ['*'])->plainTextToken;
        $this->api('GET', '/operations/'.$id)->assertNotFound();
        $this->api('GET', '/operations')->assertJsonPath('meta.total', 0);
        $this->token = $old;
        $this->actor->revokePermissionTo('system.settings_manage');
        $this->api('GET', '/operations/'.$id)->assertForbidden();
        $this->api('GET', '/operations')->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function queue_status_never_exposes_raw_exception_payload_or_false_health(): void
    {
        $this->failed();
        $response = $this->api('GET', '/queues-workers')->assertOk()
            ->assertJsonPath('data.failed_jobs.count', 1)->assertJsonPath('data.worker_health', 'unknown')
            ->assertJsonPath('data.scheduler_health', 'unknown');
        $this->assertStringNotContainsString('SECRET-CANARY', $response->getContent());
        config(['queue.default' => 'sync']);
        $this->api('GET', '/queues-workers')->assertOk()->assertJsonPath('data.pending_state', 'unsupported')
            ->assertJsonPath('data.supports_database_counts', false);
        config(['queue.failed.table' => 'absent_synthetic_table']);
        $this->api('GET', '/queues-workers')->assertOk()->assertJsonPath('data.failed_jobs.count', null);
    }

    #[Test]
    public function maintenance_preview_is_side_effect_free_and_execution_defaults_off(): void
    {
        $mock = \Mockery::mock(QueueMaintenanceAction::class);
        $mock->shouldNotReceive('invoke');
        $this->app->instance(QueueMaintenanceAction::class, $mock);
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'restart'])->assertOk()
            ->assertJsonPath('data.execution_enabled', false);
        $this->assertDatabaseCount('system_api_commands', 0);
        $this->api('POST', '/queues-workers/restart', ['confirmation' => $preview->json('data.confirmation')],
            ['Idempotency-Key' => 'disabled-restart'])->assertStatus(409);
        $this->api('POST', '/queues-workers/preview', ['action' => 'retry'])->assertUnprocessable();
        $this->api('POST', '/queues-workers/preview', ['action' => 'clear'])->assertUnprocessable();
    }

    #[Test]
    public function restart_confirmation_executes_once_and_truthfully_reports_signal_only(): void
    {
        config(['system_api.maintenance_enabled' => true]);
        $mock = \Mockery::mock(QueueMaintenanceAction::class);
        $mock->shouldReceive('invoke')->once()->with('restart', [])->andReturn(0);
        $this->app->instance(QueueMaintenanceAction::class, $mock);
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'restart'])->assertOk();
        $payload = ['confirmation' => $preview->json('data.confirmation')];
        $headers = ['Idempotency-Key' => 'restart-exact-once'];
        $response = $this->api('POST', '/queues-workers/restart', $payload, $headers)->assertOk()
            ->assertJsonPath('state', 'accepted')->assertJsonPath('replayed', false)
            ->assertJsonPath('reconciliation_required', false);
        $this->assertStringContainsString('not verified', $response->json('message'));
        $this->api('POST', '/queues-workers/restart', $payload, $headers)->assertOk()
            ->assertJsonPath('replayed', true)->assertJsonPath('operation_id', $response->json('operation_id'));
        $this->api('GET', '/operations/'.$response->json('operation_id'))->assertOk()->assertJsonPath('data.state', 'accepted');
    }

    #[Test]
    public function actual_clear_retry_one_retry_all_and_flush_touch_only_isolated_test_jobs(): void
    {
        config(['system_api.maintenance_enabled' => true]);
        DB::table('jobs')->insert(['queue' => 'synthetic-clear', 'payload' => '{}',
            'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $cases = [
            ['clear', '/queues-workers/clear', ['queue' => 'synthetic-clear']],
            ['retry', '/queues-workers/failed/retry', ['job_id' => $this->failed(), 'all' => false]],
            ['retry', '/queues-workers/failed/retry', ['all' => true]],
            ['flush', '/queues-workers/failed/flush', []],
        ];
        foreach ($cases as [$action, $path, $target]) {
            if ($action !== 'clear' && ! isset($target['job_id'])) {
                $this->failed();
            }
            $preview = $this->api('POST', '/queues-workers/preview', ['action' => $action] + $target)->assertOk();
            $response = $this->api('POST', $path, $target + ['confirmation' => $preview->json('data.confirmation')],
                ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->assertJsonPath('exit_code', 0);
            $this->assertSame($action === 'retry' ? 'accepted' : 'completed', $response->json('state'));
        }
        $this->assertDatabaseMissing('jobs', ['queue' => 'synthetic-clear']);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertGreaterThan(0, DB::table('jobs')->where('queue', 'synthetic-system')->count());
        $this->assertDatabaseCount('system_api_commands', 4);
    }

    #[Test]
    public function confirmation_expiry_tampering_target_drift_and_insufficient_action_scope_never_dispatch(): void
    {
        config(['system_api.maintenance_enabled' => true]);
        $mock = \Mockery::mock(QueueMaintenanceAction::class);
        $mock->shouldNotReceive('invoke');
        $this->app->instance(QueueMaintenanceAction::class, $mock);
        $target = ['queue' => 'synthetic'];
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'clear'] + $target)->assertOk();
        $payload = $target + ['confirmation' => $preview->json('data.confirmation')];
        DB::table('jobs')->insert(['queue' => 'synthetic', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->api('POST', '/queues-workers/clear', $payload, ['Idempotency-Key' => 'changed-cohort'])->assertStatus(409);
        $payload['confirmation'] = 'tampered';
        $this->api('POST', '/queues-workers/clear', $payload, ['Idempotency-Key' => 'tampered-preview'])->assertUnprocessable();
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'restart'])->assertOk();
        $this->travel(121)->seconds();
        $this->api('POST', '/queues-workers/restart', ['confirmation' => $preview->json('data.confirmation')],
            ['Idempotency-Key' => 'expired-preview'])->assertStatus(409);
        $this->travelBack();
        $this->token = $this->actor->createToken('Queue reads', ['system.queue.read'])->plainTextToken;
        $this->api('POST', '/queues-workers/restart', ['confirmation' => $preview->json('data.confirmation')],
            ['Idempotency-Key' => 'denied-scope'])->assertForbidden();
        $this->assertDatabaseCount('system_api_commands', 0);
    }

    #[Test]
    public function post_dispatch_exception_retains_unresolved_receipt_and_blocks_duplicate_effects(): void
    {
        config(['system_api.maintenance_enabled' => true]);
        $mock = \Mockery::mock(QueueMaintenanceAction::class);
        $mock->shouldReceive('invoke')->once()->andThrow(new \RuntimeException('SECRET-CANARY unknown backend outcome'));
        $this->app->instance(QueueMaintenanceAction::class, $mock);
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'restart'])->assertOk();
        $body = ['confirmation' => $preview->json('data.confirmation')];
        $headers = ['Idempotency-Key' => 'unresolved-dispatch'];
        $result = $this->api('POST', '/queues-workers/restart', $body, $headers)->assertStatus(202)
            ->assertJsonPath('state', 'unresolved')->assertJsonPath('reconciliation_required', true);
        $this->assertStringNotContainsString('SECRET-CANARY', $result->getContent());
        $this->api('POST', '/queues-workers/restart', $body, $headers)->assertStatus(202)->assertJsonPath('replayed', true);
        $this->api('POST', '/queues-workers/restart', $body, ['Idempotency-Key' => 'new-attempt-key'])->assertStatus(409);
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function version_api_preserves_cached_github_results_and_context_does_not_call_provider(): void
    {
        config(['app.commit' => str_repeat('a', 40), 'app.version' => '0.2.0-beta', 'app.update.github_repository' => 'example/system-test']);
        $this->api('GET', '/context')->assertOk();
        Http::assertNothingSent();
        Http::fake([
            '*releases*' => Http::response([['tag_name' => 'v0.3.0-beta', 'name' => 'Beta', 'draft' => false,
                'prerelease' => true, 'html_url' => 'https://github.com/example/system-test/releases/test']]),
            '*compare*' => Http::response(['status' => 'ahead', 'ahead_by' => 2, 'behind_by' => 0]),
        ]);
        $this->api('GET', '/version-status')->assertOk()->assertJsonPath('data.github_available', true)
            ->assertJsonPath('data.commits_behind', 2);
        $this->api('GET', '/version-status')->assertOk();
        Http::assertSentCount(2);
    }

    #[Test]
    public function invalid_images_cannot_remove_a_previous_logo_or_create_a_receipt(): void
    {
        Storage::fake('public');
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->image('old.png', 50, 20)])->assertOk();
        $previous = app(CompanyProfileSettings::class)->get()['logo_path'];
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->create('invalid.txt', 5, 'text/plain')])->assertUnprocessable();
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->image('too-large.png')->size(2049)])->assertUnprocessable();
        $this->assertSame($previous, app(CompanyProfileSettings::class)->get()['logo_path']);
        Storage::disk('public')->assertExists($previous);
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function unchanged_receipt_key_cannot_replace_uploaded_bytes_and_missing_public_files_are_truthful(): void
    {
        Storage::fake('public');
        $headers = $this->writeHeaders();
        $this->api('POST', '/branding', $this->brandingPayload(), $headers,
            ['logo' => UploadedFile::fake()->image('first.png', 50, 20)])->assertOk();
        $this->api('POST', '/branding', $this->brandingPayload(), $headers,
            ['logo' => UploadedFile::fake()->image('different.png', 70, 30)])->assertStatus(409);
        $path = app(CompanyProfileSettings::class)->get()['logo_path'];
        Storage::disk('public')->delete($path);
        $this->api('GET', '/branding')->assertOk()->assertJsonPath('data.logos.fallback.sha256', null);
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function nonzero_command_exit_is_unresolved_and_no_new_key_can_repeat_partial_effects(): void
    {
        config(['system_api.maintenance_enabled' => true]);
        $mock = \Mockery::mock(QueueMaintenanceAction::class);
        $mock->shouldReceive('invoke')->once()->andReturn(1);
        $this->app->instance(QueueMaintenanceAction::class, $mock);
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'flush'])->assertOk();
        $body = ['confirmation' => $preview->json('data.confirmation')];
        $this->api('POST', '/queues-workers/failed/flush', $body, ['Idempotency-Key' => 'nonzero-exit-test'])
            ->assertStatus(202)->assertJsonPath('state', 'unresolved')->assertJsonPath('exit_code', 1);
        $this->api('POST', '/queues-workers/failed/flush', $body, ['Idempotency-Key' => 'different-attempt'])->assertStatus(409);
    }

    #[Test]
    public function driver_clear_uses_its_supported_adapter_and_does_not_invent_cohort_evidence(): void
    {
        config(['system_api.maintenance_enabled' => true, 'queue.default' => 'redis']);
        $backend = \Mockery::mock(\Illuminate\Queue\RedisQueue::class);
        $backend->shouldReceive('size')->with('synthetic')->twice()->andReturn(3);
        \Illuminate\Support\Facades\Queue::shouldReceive('connection')->with('redis')->twice()->andReturn($backend);
        $action = \Mockery::mock(QueueMaintenanceAction::class);
        $action->shouldReceive('invoke')->once()->with('clear', ['queue' => 'synthetic'])->andReturn(0);
        $this->app->instance(QueueMaintenanceAction::class, $action);
        $preview = $this->api('POST', '/queues-workers/preview', ['action' => 'clear', 'queue' => 'synthetic'])
            ->assertOk()->assertJsonPath('data.evidence', 'driver_count_only');
        $this->api('POST', '/queues-workers/clear', ['queue' => 'synthetic', 'confirmation' => $preview->json('data.confirmation')],
            ['Idempotency-Key' => 'redis-confirmed-clear'])->assertOk()->assertJsonPath('state', 'completed');
    }

    #[Test]
    public function file_write_failure_and_receipt_rollback_keep_the_previous_usable_logo(): void
    {
        Storage::fake('public');
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->image('old.png', 30, 20)])->assertOk();
        $previous = app(CompanyProfileSettings::class)->get()['logo_path'];
        $disk = Storage::disk('public');
        $proxy = \Mockery::mock($disk);
        $proxy->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($proxy);
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->image('failed.png', 40, 20)])->assertStatus(503);
        $this->assertSame($previous, app(CompanyProfileSettings::class)->get()['logo_path']);
        $this->assertTrue($disk->exists($previous));
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function outer_receipt_failure_rolls_back_settings_and_discards_only_its_new_staged_image(): void
    {
        Storage::fake('public');
        $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
            ['logo' => UploadedFile::fake()->image('old.png', 30, 20)])->assertOk();
        $old = app(CompanyProfileSettings::class)->get()['logo_path'];
        $files = Storage::disk('public')->allFiles();
        \Illuminate\Support\Facades\Event::listen('eloquent.creating: '.SystemApiCommand::class,
            fn () => throw new \RuntimeException('Synthetic receipt persistence failure'));
        try {
            $this->api('POST', '/branding', $this->brandingPayload(), $this->writeHeaders(),
                ['logo' => UploadedFile::fake()->image('new.png', 40, 20)])->assertStatus(503);
        } finally {
            \Illuminate\Support\Facades\Event::forget('eloquent.creating: '.SystemApiCommand::class);
        }
        $this->assertSame($old, app(CompanyProfileSettings::class)->get()['logo_path']);
        $this->assertSame($files, Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('system_api_commands', 1);
    }

    #[Test]
    public function browser_maintenance_keeps_availability_and_shares_the_fenced_operation_lane(): void
    {
        $action = \Mockery::mock(QueueMaintenanceAction::class)->makePartial();
        $action->shouldReceive('invoke')->once()->with('restart', [])->andReturn(0);
        $this->app->instance(QueueMaintenanceAction::class, $action);
        $this->actingAs($this->actor)->post('/tech/admin/system/queues-workers/restart')
            ->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseCount('system_api_commands', 1);
        $this->assertSame('accepted', SystemApiCommand::first()->state);
        $this->assertFalse((bool) config('system_api.maintenance_enabled'));
        SystemApiCommand::first()->update(['state' => 'unresolved']);
        $this->actingAs($this->actor)->post('/tech/admin/system/queues-workers/restart')->assertStatus(409);
    }

    #[Test]
    public function browser_and_api_settings_share_versions_and_queue_claims(): void
    {
        $before = app(CompanyProfileSettings::class)->version();
        $this->actingAs($this->actor)->put('/tech/admin/system/company-profile', ['company_name' => 'Web update'])->assertRedirect();
        $this->assertNotSame($before, app(CompanyProfileSettings::class)->version());
        $this->api('PUT', '/company-profile', ['company_name' => 'Stale API'], $this->writeHeaders($before))->assertStatus(412);
        $this->api('GET', '/company-profile')->assertJsonPath('data.company_name', 'Web update');
    }
}
