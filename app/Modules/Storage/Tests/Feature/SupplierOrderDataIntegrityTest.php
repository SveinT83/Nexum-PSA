<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Modules\Storage\Actions\RecordSupplierOrderProfileHealth;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function database_allows_historical_policies_but_only_one_current_policy(): void
    {
        $current = $this->policy('Current policy', true);
        $this->policy('Historical one', false);
        $this->policy('Historical two', false);

        try {
            $this->policy('Concurrent current policy', true);
            $this->fail('The database must reject a second current supplier-order policy.');
        } catch (QueryException) {
            // The generated/partial unique guard is the cross-process concurrency boundary.
        }

        $this->assertSame(
            [$current->id],
            PurchaseOrderAutomationPolicy::query()->where('is_current', true)->pluck('id')->all(),
        );
        $this->assertSame(2, PurchaseOrderAutomationPolicy::query()->where('is_current', false)->count());
    }

    #[Test]
    public function database_allows_only_one_active_version_per_profile(): void
    {
        $profile = $this->profile();
        $first = $this->version($profile, 1, PurchaseOrderImportProfileVersion::STATUS_ACTIVE);
        $second = $this->version($profile, 2, PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED);
        $profile->forceFill(['active_version_id' => $first->id])->save();

        try {
            DB::table('storage_purchase_order_import_profile_versions')
                ->where('id', $second->id)
                ->update(['status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE]);
            $this->fail('The database must reject two active versions for one profile.');
        } catch (QueryException) {
            // The profile pointer is changed only after its prior active version is superseded.
        }

        $this->assertSame($first->id, $profile->fresh()->active_version_id);
        $this->assertSame(
            [$first->id],
            PurchaseOrderImportProfileVersion::query()
                ->where('profile_id', $profile->id)
                ->where('status', PurchaseOrderImportProfileVersion::STATUS_ACTIVE)
                ->pluck('id')
                ->all(),
        );
    }

    #[Test]
    public function import_attempt_rows_cannot_be_updated_or_deleted_after_creation(): void
    {
        $import = $this->import();
        $attempt = $import->attempts()->create([
            'attempt_number' => 1,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
            'status' => 'processing',
            'metadata' => ['duration_ms' => 12],
            'service_identity' => 'storage.supplier-order-import',
            'started_at' => CarbonImmutable::parse('2026-08-05 10:00:00'),
        ]);

        try {
            $attempt->forceFill(['status' => 'completed'])->save();
            $this->fail('The model must reject an attempt mutation.');
        } catch (LogicException) {
            // Model writes fail with a domain-specific error before reaching the database.
        }
        $attempt->refresh();

        try {
            DB::table('storage_purchase_order_import_attempts')
                ->where('id', $attempt->id)
                ->update(['status' => 'completed']);
            $this->fail('The database must reject a bulk attempt mutation.');
        } catch (QueryException) {
            // Bulk writes bypass model events, so the database trigger is also required.
        }

        try {
            $attempt->delete();
            $this->fail('The model must reject deleting an attempt.');
        } catch (LogicException) {
            // Attempts remain durable audit evidence.
        }

        try {
            DB::table('storage_purchase_order_import_attempts')->where('id', $attempt->id)->delete();
            $this->fail('The database must reject bulk deletion of an attempt.');
        } catch (QueryException) {
            // Direct SQL cannot bypass the append-only boundary.
        }

        try {
            DB::table('storage_purchase_order_imports')->where('id', $import->id)->delete();
            $this->fail('Deleting a parent import must not cascade away append-only attempts.');
        } catch (QueryException) {
            // The restricted parent foreign key closes the cascade-delete bypass.
        }

        $this->assertDatabaseHas('storage_purchase_order_import_attempts', [
            'id' => $attempt->id,
            'status' => 'processing',
        ]);
        $this->assertSame(['duration_ms' => 12], $attempt->fresh()->metadata);
    }

    #[Test]
    public function integrity_migration_refuses_a_partial_rollback_after_append_history_exists(): void
    {
        $import = $this->import();
        foreach (['processing', 'completed'] as $status) {
            $import->attempts()->create([
                'attempt_number' => 1,
                'stage' => PurchaseOrderImport::STAGE_DETECT,
                'status' => $status,
                'service_identity' => 'storage.supplier-order-import',
                'started_at' => CarbonImmutable::parse('2026-08-05 10:00:00'),
            ]);
        }

        $migration = require database_path(
            'migrations/2026_08_05_112000_harden_supplier_order_automation_integrity.php',
        );

        try {
            $migration->down();
            $this->fail('Rollback must stop before removing guards when append history is not reversible.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('append-only attempt history', $exception->getMessage());
        }

        try {
            DB::table('storage_purchase_order_import_attempts')
                ->where('import_id', $import->id)
                ->update(['status' => 'changed']);
            $this->fail('The rollback preflight must leave append-only guards intact.');
        } catch (QueryException) {
            // The preflight runs before any trigger, constraint, or foreign-key change.
        }

        $this->assertSame(
            ['processing', 'completed'],
            $import->attempts()->pluck('status')->all(),
        );
    }

    #[Test]
    public function stale_profile_instances_increment_health_atomically_and_cannot_undo_a_pause(): void
    {
        $profile = $this->profile([
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
            'health_state' => 'healthy',
        ]);
        $staleFirst = PurchaseOrderImportProfile::query()->findOrFail($profile->id);
        $staleSecond = PurchaseOrderImportProfile::query()->findOrFail($profile->id);
        $health = app(RecordSupplierOrderProfileHealth::class);

        $degraded = $health->failure(
            $staleFirst,
            2,
            'canonical_validation_failed',
            CarbonImmutable::parse('2026-08-05 10:00:00'),
        );
        $this->assertSame(1, $degraded->consecutive_failures);
        $this->assertSame('degraded', $degraded->health_state);

        $paused = $health->failure(
            $staleSecond,
            2,
            'canonical_validation_failed',
            CarbonImmutable::parse('2026-08-05 10:00:01'),
        );
        $this->assertSame(2, $paused->consecutive_failures);
        $this->assertSame(PurchaseOrderImportProfile::STATE_PAUSED, $paused->lifecycle_state);
        $this->assertSame('paused', $paused->health_state);

        $lateSuccess = $health->result(
            $staleFirst,
            true,
            CarbonImmutable::parse('2026-08-05 10:00:02'),
        );
        $this->assertSame(PurchaseOrderImportProfile::STATE_PAUSED, $lateSuccess->lifecycle_state);
        $this->assertSame('paused', $lateSuccess->health_state);
        $this->assertSame(2, $lateSuccess->consecutive_failures);
        $this->assertNull($lateSuccess->last_success_at);
    }

    private function policy(string $name, bool $current): PurchaseOrderAutomationPolicy
    {
        return PurchaseOrderAutomationPolicy::query()->create([
            'name' => $name,
            'is_current' => $current,
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_OFF,
            'default_outcome' => 'needs_attention',
            'ai_mode' => 'off',
            'provider_outage_behavior' => 'needs_attention',
            'advanced_rules' => [],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function profile(array $attributes = []): PurchaseOrderImportProfile
    {
        return PurchaseOrderImportProfile::query()->create($attributes + [
            'name' => 'Integrity profile',
            'slug' => 'integrity-profile-'.str()->random(8),
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
            'priority' => 100,
            'matching_scope' => [],
            'policy_overrides' => [],
            'health_state' => 'unknown',
        ]);
    }

    private function version(
        PurchaseOrderImportProfile $profile,
        int $number,
        string $status,
    ): PurchaseOrderImportProfileVersion {
        $definition = ['schema_version' => 'integrity-test', 'version' => $number];

        return PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => $number,
            'schema_version' => 'integrity-test',
            'status' => $status,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
        ]);
    }

    private function import(): PurchaseOrderImport
    {
        $snapshot = [
            'from' => ['address' => 'orders@example.invalid'],
            'subject' => 'Integrity test order',
            'received_at' => '2026-08-05T10:00:00+02:00',
            'body_text' => 'Sanitized source',
        ];

        return PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'integrity_test',
            'source_id' => 'integrity-test-1',
            'signal_action_key' => 'integrity-test-action',
            'source_action_hash' => hash('sha256', 'integrity-test-action'),
            'source_fingerprint' => StableJson::checksum($snapshot),
            'safe_source_snapshot' => $snapshot,
            'trusted_auth_snapshot' => ['authentication_passed' => true, 'aligned' => true],
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
        ]);
    }
}
