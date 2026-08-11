<?php

namespace App\Modules\Documentation\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Actions\CreateSupplierFromPurchaseImport;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\SupplierOrderAutomationActor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Verify the Documentation-owned supplier bootstrap mutation boundary.
 */
class SupplierBootstrapActionTest extends TestCase
{
    use RefreshDatabase;

    private CreateSupplierFromPurchaseImport $action;

    private int $importSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('documentation.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->action = app(CreateSupplierFromPurchaseImport::class);
    }

    #[Test]
    public function active_bootstrap_is_exactly_idempotent_for_the_same_trusted_identity_claim(): void
    {
        $actor = $this->authorizedActor();
        $firstImport = $this->createImport();
        $evidence = $this->evidence($firstImport, [
            'vendor_code' => 'SUP-ACME',
            'org_no' => 'NO123456789MVA',
            'email' => 'purchasing@acme.example',
            'url' => 'https://acme.example/suppliers',
        ]);

        $first = $this->action->handle(
            $evidence,
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );
        $retried = $this->action->handle(
            $evidence,
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );

        $secondImport = $this->createImport();
        $sameIdentityFromAnotherImport = $this->action->handle(
            $this->evidence($secondImport, [
                'vendor_code' => 'SUP-ACME',
                'org_no' => 'NO123456789MVA',
                'email' => 'purchasing@acme.example',
                'url' => 'https://acme.example/suppliers',
            ]),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );

        $this->assertTrue($first->is($retried));
        $this->assertTrue($first->is($sameIdentityFromAnotherImport));
        $this->assertDatabaseCount('vendors', 1);
        $this->assertSame($firstImport['id'], $first->created_from_purchase_import_id);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $first->supplier_import_identity_hash);
        $this->assertTrue($first->createdFromPurchaseImport->is(
            \App\Modules\Storage\Models\PurchaseOrderImport::query()->findOrFail($firstImport['id'])
        ));
    }

    #[Test]
    public function the_same_display_name_does_not_merge_distinct_authenticated_identities(): void
    {
        $actor = $this->authorizedActor();
        $firstImport = $this->createImport([
            'authenticated_supplier_identity' => 'orders@alpha-supply.example',
            'authenticated_supplier_domain' => 'alpha-supply.example',
        ]);
        $secondImport = $this->createImport([
            'authenticated_supplier_identity' => 'orders@beta-supply.example',
            'authenticated_supplier_domain' => 'beta-supply.example',
        ]);

        $first = $this->action->handle(
            $this->evidence($firstImport, ['supplier_name' => 'Shared Supplier Name']),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );
        $second = $this->action->handle(
            $this->evidence($secondImport, ['supplier_name' => 'Shared Supplier Name']),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );

        $this->assertFalse($first->is($second));
        $this->assertNotSame(
            $first->supplier_import_identity_hash,
            $second->supplier_import_identity_hash
        );
        $this->assertDatabaseCount('vendors', 2);
        $this->assertSame(2, Vendor::query()->where('name', 'Shared Supplier Name')->count());
    }

    #[Test]
    public function exact_master_data_collision_is_rejected_instead_of_merged(): void
    {
        Vendor::query()->create([
            'name' => 'Existing Manual Supplier',
            'vendor_code' => 'CONFLICT-001',
            'org_no' => 'NO999999999MVA',
            'is_vendor' => true,
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $import = $this->createImport([
            'authenticated_supplier_identity' => 'orders@new-supplier.example',
            'authenticated_supplier_domain' => 'new-supplier.example',
        ]);

        $this->assertValidationRejected(fn () => $this->action->handle(
            $this->evidence($import, ['vendor_code' => 'CONFLICT-001']),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $this->authorizedActor()
        ));

        $this->assertDatabaseCount('vendors', 1);
        $this->assertDatabaseMissing('vendors', [
            'created_from_purchase_import_id' => $import['id'],
        ]);
    }

    #[Test]
    public function active_bootstrap_rejects_missing_inactive_and_unauthorized_actors(): void
    {
        $missingActorImport = $this->createImport();

        $this->assertAuthorizationRejected(fn () => $this->action->handle(
            $this->evidence($missingActorImport),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE
        ));

        $inactiveActor = $this->authorizedActor(User::STATUS_DISABLED);
        $inactiveImport = $this->createImport([
            'authenticated_supplier_identity' => 'orders@inactive-actor.example',
            'authenticated_supplier_domain' => 'inactive-actor.example',
        ]);

        $this->assertAuthorizationRejected(fn () => $this->action->handle(
            $this->evidence($inactiveImport),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $inactiveActor
        ));

        $unauthorizedActor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unauthorizedImport = $this->createImport([
            'authenticated_supplier_identity' => 'orders@unauthorized-actor.example',
            'authenticated_supplier_domain' => 'unauthorized-actor.example',
        ]);

        $this->assertAuthorizationRejected(fn () => $this->action->handle(
            $this->evidence($unauthorizedImport),
            CreateSupplierFromPurchaseImport::MODE_REVIEW_CANDIDATE,
            $unauthorizedActor
        ));

        $this->assertDatabaseCount('vendors', 0);
    }

    #[Test]
    public function protected_supplier_order_system_actor_can_create_an_active_supplier(): void
    {
        $actor = app(SupplierOrderAutomationActor::class)->resolve();
        $import = $this->createImport([
            'authenticated_supplier_identity' => 'orders@system-actor.example',
            'authenticated_supplier_domain' => 'system-actor.example',
        ]);

        $vendor = $this->action->handle(
            $this->evidence($import),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor,
        );

        $this->assertTrue($vendor->is_active);
        $this->assertTrue($actor->isSystemActor());
        $this->assertSame(User::STATUS_DISABLED, $actor->status);
        $this->assertSame($actor->id, $vendor->source_provenance['bootstrap']['actor_id']);
    }

    #[Test]
    public function untrusted_review_candidate_is_inactive_idempotent_and_cannot_claim_an_existing_identity(): void
    {
        $actor = $this->authorizedActor();
        $trustedImport = $this->createImport();
        $active = $this->action->handle(
            $this->evidence($trustedImport),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        );

        $untrustedAuth = [
            'authentication_passed' => false,
            'aligned' => false,
            'authenticated_supplier_identity' => 'orders@acme.example',
            'authenticated_supplier_domain' => 'acme.example',
            'authserv_id' => 'mx.nexum.example',
            'spf' => 'fail',
            'dkim' => 'none',
            'dmarc' => 'fail',
        ];
        $untrustedImport = $this->createImport($untrustedAuth);
        $candidateEvidence = $this->evidence($untrustedImport, [
            'service_identity' => 'storage.purchase-import',
        ]);

        $candidate = $this->action->handle(
            $candidateEvidence,
            CreateSupplierFromPurchaseImport::MODE_REVIEW_CANDIDATE
        );
        $retried = $this->action->handle(
            $candidateEvidence,
            CreateSupplierFromPurchaseImport::MODE_REVIEW_CANDIDATE
        );

        $this->assertFalse($active->is($candidate));
        $this->assertTrue($candidate->is($retried));
        $this->assertTrue($active->is_active);
        $this->assertSame(CreateSupplierFromPurchaseImport::MODE_ACTIVE, $active->supplier_bootstrap_status);
        $this->assertNotNull($active->supplier_import_identity_hash);
        $this->assertFalse($candidate->is_active);
        $this->assertTrue($candidate->is_vendor);
        $this->assertTrue($candidate->is_supplier);
        $this->assertSame(
            CreateSupplierFromPurchaseImport::MODE_REVIEW_CANDIDATE,
            $candidate->supplier_bootstrap_status
        );
        $this->assertNull($candidate->supplier_import_identity_hash);
        $this->assertDatabaseCount('vendors', 2);
    }

    #[Test]
    public function unsupported_bootstrap_mode_is_rejected(): void
    {
        $import = $this->createImport();

        $this->assertValidationRejected(fn () => $this->action->handle(
            $this->evidence($import),
            'automatic',
            $this->authorizedActor()
        ));

        $this->assertDatabaseCount('vendors', 0);
        $this->assertDatabaseHas('storage_purchase_order_imports', [
            'id' => $import['id'],
            'vendor_id' => null,
        ]);
    }

    #[Test]
    public function active_bootstrap_rejects_failed_or_misaligned_authentication(): void
    {
        $failedImport = $this->createImport([
            'authentication_passed' => false,
            'aligned' => false,
            'spf' => 'fail',
            'dkim' => 'none',
            'dmarc' => 'fail',
        ]);

        $this->assertValidationRejected(fn () => $this->action->handle(
            $this->evidence($failedImport),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $this->authorizedActor()
        ));

        $this->assertDatabaseCount('vendors', 0);
    }

    #[Test]
    public function source_fingerprint_and_trusted_auth_snapshot_must_match_the_import_ledger(): void
    {
        $actor = $this->authorizedActor();
        $import = $this->createImport();

        $this->assertValidationRejected(fn () => $this->action->handle(
            $this->evidence($import, [
                'source_fingerprint' => hash('sha256', 'tampered-source'),
            ]),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        ));

        $this->assertValidationRejected(fn () => $this->action->handle(
            $this->evidence($import, ['dkim' => 'fail']),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $actor
        ));

        $this->assertDatabaseCount('vendors', 0);
    }

    #[Test]
    public function provenance_is_allowlisted_and_drops_raw_bodies_headers_and_secrets(): void
    {
        $import = $this->createImport();
        $vendor = $this->action->handle(
            $this->evidence($import, [
                'org_no' => 'NO123456789MVA',
                'email' => 'supplier-contact@acme.example',
                'url' => 'https://acme.example/orders',
                'vendor_code' => 'ACME-EXACT',
                'raw_body' => 'TOP SECRET RAW BODY',
                'raw_headers' => ['authorization' => 'Bearer forbidden-token'],
                'secret' => 'forbidden-secret',
                'api_token' => 'forbidden-api-token',
                'nested' => ['password' => 'forbidden-password'],
            ]),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $this->authorizedActor()
        );

        $this->assertSame(
            ['schema_version', 'source', 'authentication', 'identity_claim', 'bootstrap'],
            array_keys($vendor->source_provenance)
        );
        $this->assertSame([
            'authentication_passed',
            'aligned',
            'authenticated_supplier_identity',
            'authenticated_supplier_domain',
            'authserv_id',
            'spf',
            'dkim',
            'dmarc',
        ], array_keys($vendor->source_provenance['authentication']));

        $serialized = json_encode($vendor->source_provenance, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('TOP SECRET RAW BODY', $serialized);
        $this->assertStringNotContainsString('forbidden-token', $serialized);
        $this->assertStringNotContainsString('forbidden-secret', $serialized);
        $this->assertStringNotContainsString('forbidden-api-token', $serialized);
        $this->assertStringNotContainsString('forbidden-password', $serialized);
        $this->assertStringNotContainsString('raw_body', $serialized);
        $this->assertStringNotContainsString('raw_headers', $serialized);

        $this->assertSame('NO123456789MVA', $vendor->org_no);
        $this->assertSame('supplier-contact@acme.example', $vendor->email);
        $this->assertSame('https://acme.example/orders', $vendor->url);
        $this->assertSame('ACME-EXACT', $vendor->vendor_code);
    }

    #[Test]
    public function supplier_bootstrap_has_no_purchase_order_item_or_stock_side_effects(): void
    {
        $before = $this->storageOperationalCounts();
        $import = $this->createImport();

        $this->action->handle(
            $this->evidence($import),
            CreateSupplierFromPurchaseImport::MODE_ACTIVE,
            $this->authorizedActor()
        );

        $this->assertSame($before, $this->storageOperationalCounts());
        $this->assertDatabaseCount('vendors', 1);
    }

    /**
     * @param  array<string, mixed>  $authOverrides
     * @return array{id: int, source_fingerprint: string, authentication: array<string, mixed>}
     */
    private function createImport(array $authOverrides = []): array
    {
        $this->importSequence++;
        $authentication = array_replace([
            'authentication_passed' => true,
            'aligned' => true,
            'authenticated_supplier_identity' => 'orders@acme.example',
            'authenticated_supplier_domain' => 'acme.example',
            'authserv_id' => 'mx.nexum.example',
            'spf' => 'pass',
            'dkim' => 'pass',
            'dmarc' => 'pass',
        ], $authOverrides);
        $sourceFingerprint = hash('sha256', 'source-'.$this->importSequence);
        $now = now();

        $id = DB::table('storage_purchase_order_imports')->insertGetId([
            'source_domain' => 'email',
            'source_type' => 'supplier_order_confirmation',
            'source_id' => 'email-message-'.$this->importSequence,
            'signal_action_key' => 'signal:'.$this->importSequence.':rule:1:action:0',
            'source_action_hash' => hash('sha256', 'action-'.$this->importSequence),
            'source_fingerprint' => $sourceFingerprint,
            'safe_source_snapshot' => json_encode([
                'subject' => 'Supplier order '.$this->importSequence,
            ], JSON_THROW_ON_ERROR),
            'trusted_auth_snapshot' => json_encode($authentication, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'stage' => 'detect',
            'canonical_schema_version' => '1.0',
            'parser_version' => '1.0',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'source_fingerprint' => $sourceFingerprint,
            'authentication' => $authentication,
        ];
    }

    /**
     * @param  array{id: int, source_fingerprint: string, authentication: array<string, mixed>}  $import
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function evidence(array $import, array $overrides = []): array
    {
        return array_replace([
            'source_import_id' => $import['id'],
            'source_fingerprint' => $import['source_fingerprint'],
            'supplier_name' => 'Acme Supplier AS',
            'service_identity' => 'storage.purchase-import',
        ], $import['authentication'], $overrides);
    }

    private function authorizedActor(string $status = User::STATUS_ACTIVE): User
    {
        $actor = User::factory()->create(['status' => $status]);
        $actor->givePermissionTo('documentation.create');

        return $actor;
    }

    /**
     * @return array<string, int>
     */
    private function storageOperationalCounts(): array
    {
        return [
            'purchase_orders' => DB::table('storage_purchase_orders')->count(),
            'items' => DB::table('storage_items')->count(),
            'stock_units' => DB::table('storage_stock_units')->count(),
            'movements' => DB::table('storage_movements')->count(),
        ];
    }

    private function assertAuthorizationRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the supplier bootstrap action to reject authorization.');
    }

    private function assertValidationRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the supplier bootstrap action to reject unsafe evidence.');
    }
}
