<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TripletexConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
    }

    private function admin(bool $permission = true): User
    {
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($role);
        if ($permission) {
            $user->givePermissionTo('integration.tripletex_manage');
        }

        return $user;
    }

    private function connection(): Integration
    {
        $row = Integration::create(['name' => 'Own company', 'type' => 'tripletex', 'status' => 'disabled',
            'config' => ['company_id' => 42, 'environment' => 'test', 'version' => 1]]);
        $row->setSecret('refresh_token', 'synthetic-refresh-token-for-tests');
        $row->save();

        return $row;
    }

    private function fake(array $additional = []): void
    {
        Http::fake($additional + [
            '*/token/session/:createFromRefreshToken' => Http::response(['value' => ['token' => 'synthetic-session']], 201),
            '*/token/session/%3EwhoAmI' => Http::response(['value' => ['company' => ['id' => 42]]]),
        ]);
    }

    private function writer(Integration $row): TripletexClient
    {
        config(['tripletex.enabled' => true, 'tripletex.writes_enabled' => true]);
        $row->update(['status' => 'active', 'config' => $row->config + ['write_contract_verified' => true]]);

        return new TripletexClient($row);
    }

    private function entry(): array
    {
        return ['employee' => ['id' => 2], 'activity' => ['id' => 3], 'project' => null,
            'date' => '2026-10-05', 'hours' => 0.75, 'comment' => 'Synthetic work'];
    }

    public function test_save_encrypts_token_and_does_not_activate_transfer(): void
    {
        $this->actingAs($this->admin())->post(route('tech.admin.system.integrations.tripletex.store'), [
            'name' => 'Own company', 'environment' => 'test', 'company_id' => 42, 'version' => 0,
            'refresh_token' => 'synthetic-refresh-token-for-tests',
        ])->assertRedirect(route('tech.admin.system.integrations.tripletex.index'));
        $row = Integration::where('type', 'tripletex')->sole();
        $this->assertSame('synthetic-refresh-token-for-tests', $row->getSecret('refresh_token'));
        $this->assertStringNotContainsString('synthetic-refresh-token-for-tests', $row->getRawOriginal('secrets'));
        $this->assertSame('disabled', $row->status);
        $this->assertFalse($row->config['write_contract_verified']);
        $this->get(route('tech.admin.system.integrations.tripletex.index'))->assertOk()
            ->assertSee('Time transfer is not activated')->assertDontSee('synthetic-refresh-token-for-tests');
        Http::assertNothingSent();
    }

    public function test_validation_never_flashes_credentials(): void
    {
        $this->actingAs($this->admin())->from(route('tech.admin.system.integrations.tripletex.index'))
            ->post(route('tech.admin.system.integrations.tripletex.store'), [
                'name' => '', 'environment' => 'test', 'company_id' => 42, 'version' => 0,
                'refresh_token' => 'synthetic-refresh-token-for-tests',
            ])->assertSessionHasErrors('name')->assertSessionMissing('_old_input.refresh_token');
    }

    public function test_setup_denies_an_admin_without_explicit_permission(): void
    {
        $this->actingAs($this->admin(false))->get(route('tech.admin.system.integrations.tripletex.index'))->assertForbidden();
    }

    public function test_verify_reads_company_and_preserves_disabled_status(): void
    {
        $row = $this->connection();
        $this->fake();
        $this->actingAs($this->admin())->post(route('tech.admin.system.integrations.tripletex.verify', $row->id))->assertRedirect();
        $row->refresh();
        $this->assertSame(42, $row->config['verified_company_id']);
        $this->assertSame('disabled', $row->status);
        $this->assertNotEmpty($row->config['read_verified_at']);
        $this->assertArrayNotHasKey('session_token', $row->secrets);
    }

    public function test_wrong_company_prevents_time_reads(): void
    {
        $this->fake(['*/token/session/%3EwhoAmI' => Http::response(['value' => ['companyId' => 99]])]);
        try {
            (new TripletexClient($this->connection()))->entries('2026-10-05', '2026-10-06', 2);
            $this->fail('Expected wrong-company denial');
        } catch (TripletexException $e) {
            $this->assertSame('company_identity_mismatch', $e->reason);
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/timesheet/'));
    }

    public function test_incomplete_pagination_is_not_a_successful_scan(): void
    {
        $this->fake(['*/timesheet/entry?*' => Http::response(['values' => [['id' => 5]], 'fullResultSize' => 2])]);
        $this->expectExceptionMessage('incomplete provider list');
        (new TripletexClient($this->connection()))->entries('2026-10-05', '2026-10-06', 2);
    }

    public function test_complete_empty_scan_is_valid(): void
    {
        $this->fake(['*/timesheet/entry?*' => Http::response(['values' => [], 'fullResultSize' => 0])]);
        $this->assertSame([], (new TripletexClient($this->connection()))->entries('2026-10-05', '2026-10-06', 2));
    }

    public function test_write_switches_block_even_a_configured_connection(): void
    {
        $this->expectExceptionMessage('writes not activated');
        (new TripletexClient($this->connection()))->createEntry($this->entry());
    }

    public function test_create_fractional_duration_requires_remote_readback(): void
    {
        $entry = $this->entry();
        $this->fake([
            '*/timesheet/entry' => Http::response(['value' => ['id' => 8]], 201),
            '*/timesheet/entry/8' => Http::response(['value' => $entry + ['id' => 8, 'version' => 1, 'locked' => false]]),
        ]);
        $read = $this->writer($this->connection())->createEntry($entry);
        $this->assertSame(0.75, $read['hours']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/timesheet/entry') && $r['hours'] === 0.75);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), '/timesheet/entry/8'));
    }

    public function test_readback_rounding_is_not_reported_as_success(): void
    {
        $entry = $this->entry();
        $entry['hours'] = 1 / 60;
        $this->fake([
            '*/timesheet/entry' => Http::response(['value' => ['id' => 8]], 201),
            '*/timesheet/entry/8' => Http::response(['value' => array_replace($entry, ['id' => 8, 'hours' => 0.02])]),
        ]);
        $this->expectExceptionMessage('duration readback mismatch');
        $this->writer($this->connection())->createEntry($entry);
    }

    public function test_locked_entry_cannot_be_updated_or_deleted(): void
    {
        $this->fake(['*/timesheet/entry/8' => Http::response(['value' => $this->entry() + ['id' => 8, 'version' => 1, 'locked' => true]])]);
        $client = $this->writer($this->connection());
        foreach (['update', 'delete'] as $operation) {
            try {
                $operation === 'update' ? $client->updateEntry(8, 1, $this->entry()) : $client->deleteEntry(8, 1);
                $this->fail('Locked write accepted');
            } catch (TripletexException $e) {
                $this->assertSame('entry_locked_or_unknown', $e->reason);
            }
        }
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PUT', 'DELETE']));
    }

    public function test_stale_version_blocks_deletion(): void
    {
        $this->fake(['*/timesheet/entry/8' => Http::response(['value' => $this->entry() + ['id' => 8, 'version' => 2, 'locked' => false]])]);
        $this->expectExceptionMessage('entry changed');
        $this->writer($this->connection())->deleteEntry(8, 1);
    }

    public function test_delete_uses_version_and_verifies_absence_in_an_authorized_list(): void
    {
        $row = $this->entry() + ['id' => 8, 'version' => 2, 'locked' => false];
        $this->fake([
            '*/timesheet/entry/8' => Http::sequence()->push(['value' => $row])->push([], 404),
            '*/timesheet/entry/8?version=2' => Http::response(null, 204),
            '*/timesheet/entry?*' => Http::response(['values' => [], 'fullResultSize' => 0]),
        ]);
        $this->writer($this->connection())->deleteEntry(8, 2);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '?version=2'));
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), '/timesheet/entry?'));
    }

    public function test_rejected_provider_body_is_never_exposed(): void
    {
        $this->fake(['*/token/session/:createFromRefreshToken' => Http::response(['error' => 'SENSITIVE TOKEN'], 403)]);
        try {
            (new TripletexClient($this->connection()))->verifyCompany();
            $this->fail('Expected denial');
        } catch (TripletexException $e) {
            $this->assertStringNotContainsString('SENSITIVE', $e->getMessage());
            $this->assertNull($e->getPrevious());
            $this->assertSame(403, $e->status);
        }
    }

    public function test_verified_connection_cannot_be_rebound_to_another_company(): void
    {
        $row = $this->connection();
        $row->update(['config' => $row->config + ['verified_company_id' => 42]]);
        $this->actingAs($this->admin())->put(route('tech.admin.system.integrations.tripletex.update', $row->id), [
            'name' => 'Changed company', 'environment' => 'test', 'company_id' => 99, 'version' => 1,
        ])->assertConflict();
        $this->assertSame(42, $row->fresh()->config['company_id']);
    }

    public function test_two_companies_do_not_share_session_tokens(): void
    {
        $one = $this->connection();
        // Separate installation snapshots still must not share in-memory sessions.
        $two = $one->replicate();
        $two->config = array_replace($two->config, ['company_id' => 99]);
        Http::fake([
            '*/token/session/:createFromRefreshToken' => Http::sequence()
                ->push(['value' => ['token' => 'company-one']], 201)
                ->push(['value' => ['token' => 'company-two']], 201),
            '*/token/session/%3EwhoAmI' => Http::sequence()
                ->push(['value' => ['companyId' => 42]])->push(['value' => ['companyId' => 99]]),
        ]);
        $this->assertSame(42, (new TripletexClient($one))->verifyCompany()['company_id']);
        $this->assertSame(99, (new TripletexClient($two))->verifyCompany()['company_id']);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('0:company-one')));
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('0:company-two')));
    }

    public function test_candidates_are_read_back_without_automatic_matching(): void
    {
        $row = $this->connection();
        $this->fake([
            '*/employee?*' => Http::response(['fullResultSize' => 1, 'values' => [['id' => 2, 'firstName' => 'Test', 'lastName' => 'Worker']]]),
            '*/activity?*' => Http::response(['fullResultSize' => 1, 'values' => [['id' => 3, 'name' => 'General work']]]),
            '*/project?*' => Http::response(['fullResultSize' => 0, 'values' => []]),
        ]);
        $response = $this->actingAs($this->admin())->post(route('tech.admin.system.integrations.tripletex.candidates', $row->id))
            ->assertOk()->assertSee('Test Worker')->assertSee('General work');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertArrayNotHasKey('employee_mappings', $row->fresh()->config);
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['PUT', 'DELETE']));
    }

    public function test_update_uses_current_version_and_readback(): void
    {
        $before = $this->entry() + ['id' => 8, 'version' => 2, 'locked' => false];
        $after = array_replace($before, ['hours' => 1.5, 'version' => 3]);
        $this->fake(['*/timesheet/entry/8' => Http::sequence()
            ->push(['value' => $before])->push(['value' => $after])->push(['value' => $after])]);
        $result = $this->writer($this->connection())->updateEntry(8, 2, array_replace($this->entry(), ['hours' => 1.5]));
        $this->assertSame(1.5, $result['hours']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['version'] === 2 && $r['hours'] === 1.5);
    }

    public function test_unknown_write_outcome_is_not_retried(): void
    {
        $this->fake(['*/timesheet/entry' => Http::failedConnection('DO NOT LOG SECRET')]);
        try {
            $this->writer($this->connection())->createEntry($this->entry());
            $this->fail('Expected ambiguous result');
        } catch (TripletexException $e) {
            $this->assertSame('write_outcome_unknown', $e->reason);
            $this->assertNull($e->getPrevious());
            $this->assertStringNotContainsString('SECRET', (string) $e);
        }
        Http::assertSentCount(3);
    }

    public function test_delete_access_loss_is_not_verified_as_success(): void
    {
        $before = $this->entry() + ['id' => 8, 'version' => 2, 'locked' => false];
        $this->fake([
            '*/timesheet/entry/8' => Http::sequence()->push(['value' => $before])->push([], 404),
            '*/timesheet/entry/8?version=2' => Http::response(null, 204),
            '*/timesheet/entry?*' => Http::response([], 403),
        ]);
        $this->expectExceptionMessage('request failed');
        $this->writer($this->connection())->deleteEntry(8, 2);
    }

    public function test_generic_toggle_cannot_activate_tripletex(): void
    {
        $row = $this->connection();
        $this->actingAs($this->admin())->post(route('tech.admin.system.integrations.toggle'), [
            'type' => 'tripletex', 'name' => 'Tripletex',
        ])->assertSessionHasErrors('type');
        $this->assertSame('disabled', $row->fresh()->status);
    }

    public function test_empty_setup_shows_one_connection_form(): void
    {
        $this->actingAs($this->admin())->get(route('tech.admin.system.integrations.tripletex.index'))
            ->assertOk()->assertSee('Set up Tripletex')
            ->assertSee('placeholder="Enter your company API token"', false)
            ->assertSee('action="'.route('tech.admin.system.integrations.tripletex.store').'"', false);
    }

    public function test_existing_connection_has_only_its_settings_form(): void
    {
        $row = $this->connection();
        $this->actingAs($this->admin())->get(route('tech.admin.system.integrations.tripletex.index'))
            ->assertOk()->assertSee('Tripletex connection')
            ->assertSee('placeholder="Leave blank to keep the existing token"', false)
            ->assertDontSee('Use this company&#039;s internal API token.', false)
            ->assertSee('action="'.route('tech.admin.system.integrations.tripletex.update', $row->id).'"', false)
            ->assertDontSee('action="'.route('tech.admin.system.integrations.tripletex.store').'"', false)
            ->assertDontSee('Set up Tripletex')->assertDontSee('Add connection');
    }

    public function test_a_second_setup_request_preserves_the_existing_verified_account(): void
    {
        $row = $this->connection();
        $row->update(['config' => $row->config + ['verified_company_id' => 42, 'read_verified_at' => now()->toIso8601String()]]);
        $before = $row->fresh()->getAttributes();
        $newToken = 'synthetic-'.\Illuminate\Support\Str::random(64);
        $this->actingAs($this->admin())->post(route('tech.admin.system.integrations.tripletex.store'), [
            'name' => 'Second account', 'environment' => 'production', 'company_id' => 99, 'version' => 0,
            'refresh_token' => $newToken,
        ])->assertConflict()->assertDontSee($newToken)
            ->assertSessionMissing('_old_input.refresh_token');
        $this->assertSame(1, Integration::where('type', 'tripletex')->count());
        $this->assertSame($before, $row->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    public function test_existing_account_can_keep_or_rotate_its_token_without_creating_another(): void
    {
        $row = $this->connection();
        $this->actingAs($this->admin())->put(route('tech.admin.system.integrations.tripletex.update', $row->id), [
            'name' => 'Updated label', 'environment' => 'test', 'company_id' => 42, 'version' => 1,
            'refresh_token' => '',
        ])->assertRedirect();
        $this->assertSame('synthetic-refresh-token-for-tests', $row->fresh()->getSecret('refresh_token'));
        $this->put(route('tech.admin.system.integrations.tripletex.update', $row->id), [
            'name' => 'Updated label', 'environment' => 'test', 'company_id' => 42, 'version' => 2,
            'refresh_token' => 'rotated-synthetic-refresh-token',
        ])->assertRedirect();
        $this->assertSame('rotated-synthetic-refresh-token', $row->fresh()->getSecret('refresh_token'));
        $this->assertSame(1, Integration::where('type', 'tripletex')->count());
        Http::assertNothingSent();
    }

    public function test_database_prevents_an_additional_account_even_without_the_controller(): void
    {
        $this->connection();
        // Different providers still allow multiple instances.
        foreach ([1, 2] as $number) {
            Integration::create(['name' => 'Other '.$number, 'type' => 'other-provider']);
        }
        $this->assertSame(2, Integration::where('type', 'other-provider')->count());
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->connection();
    }

    public function test_singleton_migration_round_trip_preserves_the_existing_account(): void
    {
        $row = $this->connection();
        $migration = require database_path('migrations/2026_10_05_210000_enforce_single_tripletex_connection.php');
        $migration->down();
        $before = $row->fresh()->getAttributes();
        $migration->up();
        $migration->down();
        $this->assertSame($before, $row->fresh()->getAttributes());
        $migration->up();
        $this->assertSame(1, Integration::where('type', 'tripletex')->count());
    }

    public function test_singleton_upgrade_rejects_legacy_duplicates_without_deleting_any(): void
    {
        $migration = require database_path('migrations/2026_10_05_210000_enforce_single_tripletex_connection.php');
        $migration->down();
        $first = $this->connection();
        $second = $this->connection();
        try {
            $migration->up();
            $this->fail('Expected legacy duplicates to stop the migration');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Multiple Tripletex connections exist', $e->getMessage());
        }
        $this->assertSame(2, Integration::where('type', 'tripletex')->count());
        $this->assertNotNull($first->fresh());
        $this->assertNotNull($second->fresh());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('integrations', 'tripletex_singleton'));
    }
}
