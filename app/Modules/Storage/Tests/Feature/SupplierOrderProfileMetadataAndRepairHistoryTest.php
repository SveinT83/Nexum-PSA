<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierOrderProfileMetadataAndRepairHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'storage.purchase_import_view',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo([
            'storage.purchase_import_view',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_view',
        ]);
        $this->actor->assignRole(Role::findOrCreate('Admin', 'web'));
    }

    #[Test]
    public function profile_metadata_update_is_audited_without_mutating_the_active_parser_version(): void
    {
        [$profile, $version] = $this->profileAndVersion('audited-profile');
        $definitionBefore = $version->definition;
        $checksumBefore = $version->checksum;
        $scope = SupplierOrderProfileFactoryData::itegraMatchingScope();
        $scope['recipients'] = ['receiving@example.invalid'];

        $this->actingAs($this->actor)
            ->from(route('tech.admin.settings.storage.supplier-order-profiles.edit', $profile))
            ->put(route('tech.admin.settings.storage.supplier-order-profiles.update', $profile), [
                'name' => 'Audited Profile Renamed',
                'slug' => 'audited-profile-renamed',
                'description' => 'Updated descriptive metadata only.',
                'matching_scope' => json_encode($scope, JSON_THROW_ON_ERROR),
                'reason' => 'Aligned the container metadata with the supplier governance register.',
            ])
            ->assertRedirect(route('tech.admin.settings.storage.supplier-order-profiles.show', $profile))
            ->assertSessionHas('success');

        $freshProfile = $profile->fresh(['metadataAudits.actor']);
        $freshVersion = $version->fresh();
        $audit = $freshProfile->metadataAudits->sole();

        $this->assertSame('Audited Profile Renamed', $freshProfile->name);
        $this->assertSame('audited-profile-renamed', $freshProfile->slug);
        $this->assertSame('Updated descriptive metadata only.', $freshProfile->description);
        $this->assertSame($scope, $freshProfile->matching_scope);
        $this->assertSame($version->id, $freshProfile->active_version_id);
        $this->assertSame($definitionBefore, $freshVersion->definition);
        $this->assertSame($checksumBefore, $freshVersion->checksum);
        $this->assertSame(
            ['name', 'slug', 'description', 'matching_scope'],
            $audit->changed_fields,
        );
        $this->assertSame($this->actor->id, $audit->actor_id);
        $this->assertSame($version->id, data_get($audit->before_snapshot, 'active_version_id'));
        $this->assertSame($checksumBefore, data_get($audit->before_snapshot, 'active_version_checksum'));
        $this->assertSame($checksumBefore, data_get($audit->after_snapshot, 'active_version_checksum'));

        $this->actingAs($this->actor)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.show', $freshProfile))
            ->assertOk()
            ->assertSee('Metadata Audit Trail')
            ->assertSee('Aligned the container metadata with the supplier governance register.')
            ->assertSee('Runtime matching remains pinned to the active immutable version');

        $this->expectException(LogicException::class);
        $audit->forceFill(['reason' => 'Attempted audit rewrite'])->save();
    }

    #[Test]
    public function profile_metadata_update_rejects_unauthorized_invalid_and_duplicate_changes(): void
    {
        [$profile] = $this->profileAndVersion('protected-profile');
        [$otherProfile] = $this->profileAndVersion('reserved-profile');
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unauthorized->givePermissionTo('storage.purchase_import_view');
        $validScope = SupplierOrderProfileFactoryData::itegraMatchingScope();

        $this->actingAs($unauthorized)
            ->put(route('tech.admin.settings.storage.supplier-order-profiles.update', $profile), [
                'name' => 'Unauthorized Name',
                'slug' => 'unauthorized-name',
                'description' => null,
                'matching_scope' => json_encode($validScope, JSON_THROW_ON_ERROR),
                'reason' => 'This write must be rejected.',
            ])
            ->assertForbidden();

        $invalidScope = array_fill_keys([
            'account_ids',
            'mailboxes',
            'recipients',
            'senders',
            'sender_domains',
            'subject_markers',
            'body_markers',
            'authenticated_supplier_domains',
        ], []);
        $invalidScope['require_trusted_auth'] = true;
        $invalidScope['require_aligned'] = true;

        $this->actingAs($this->actor)
            ->from(route('tech.admin.settings.storage.supplier-order-profiles.edit', $profile))
            ->put(route('tech.admin.settings.storage.supplier-order-profiles.update', $profile), [
                'name' => $profile->name,
                'slug' => $profile->slug,
                'description' => $profile->description,
                'matching_scope' => json_encode($invalidScope, JSON_THROW_ON_ERROR),
                'reason' => 'Attempt an invalid empty selector scope.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('matching_scope');

        $this->actingAs($this->actor)
            ->from(route('tech.admin.settings.storage.supplier-order-profiles.edit', $profile))
            ->put(route('tech.admin.settings.storage.supplier-order-profiles.update', $profile), [
                'name' => $profile->name,
                'slug' => $otherProfile->slug,
                'description' => $profile->description,
                'matching_scope' => json_encode($validScope, JSON_THROW_ON_ERROR),
                'reason' => 'Attempt a duplicate immutable profile identity.',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('slug');

        $this->assertSame('Protected Profile', $profile->fresh()->name);
        $this->assertSame('protected-profile', $profile->fresh()->slug);
        $this->assertDatabaseCount('storage_purchase_order_import_profile_metadata_audits', 0);
    }

    #[Test]
    public function repair_history_shows_bounded_diff_evidence_and_derived_operational_outcomes(): void
    {
        [$profile, $version] = $this->profileAndVersion('repair-history-profile');
        $candidateDefinition = $version->definition;
        data_set($candidateDefinition, 'fields.currency.value', 'EUR');
        $candidate = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 2,
            'parent_version_id' => $version->id,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_DRAFT,
            'definition' => $candidateDefinition,
            'checksum' => StableJson::checksum($candidateDefinition),
            'source' => 'ai_repair',
            'created_by' => $this->actor->id,
        ]);
        $original = $this->document('ORDER-ORIGINAL', 'Original order evidence');
        $first = $this->document('ORDER-FIRST', 'First repair evidence');
        $current = $this->document('ORDER-CURRENT', 'Verified current repair evidence');
        $blocked = $this->document('ORDER-BLOCKED', 'Blocked proposal evidence');
        $blocked['delivery']['address'] = 'SECRET DELIVERY ADDRESS';
        $blocked['unknown_fields'] = ['raw_model_prompt' => 'RAW-PROMPT-SECRET'];
        $blocked['evidence']['external_order_number']['prompt'] = 'RAW-PROMPT-SECRET';
        $import = $this->supplierImport($profile, $version, $current);

        $this->repair($import, 1, PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS, $first, [
            'method' => 'ai',
            'diagnosis' => 'The first parser mapping was incomplete.',
            'change_summary' => ['Corrected the first order number.'],
            // Deliberately no before_document: this represents a legacy first repair.
        ], $original);
        $this->repair($import, 2, PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS, $current, [
            'method' => 'ai',
            'diagnosis' => 'The supplier label moved.',
            'change_summary' => ['Corrected the current order number.'],
            'before_document' => $first,
            'blocked_reason' => null,
            'candidate_reproduction' => [
                'current_samples' => 1,
                'protected_fixture_samples' => 2,
                'historical_samples' => 3,
            ],
            'ai_budget' => [
                'limit' => '5.00',
                'currency' => 'NOK',
                'spent' => '1.25',
                'remaining' => '3.75',
                'reason_code' => 'within_budget',
            ],
            'primary_execution' => [
                'execution_id' => 'primary-execution-id',
                'workload_id' => 10,
                'workload_slug' => 'supplier-order-primary',
                'provider_id' => 20,
                'agent_id' => 30,
                'access_event_id' => 40,
                'provider_reported_cost' => '1.00',
                'cost_currency' => 'NOK',
            ],
            'consensus' => [
                'status' => 'matched',
                'execution_id' => 'consensus-execution-id',
                'workload_id' => 11,
                'workload_slug' => 'supplier-order-consensus',
                'provider_id' => 21,
                'agent_id' => 31,
                'access_event_id' => 41,
                'provider_reported_cost' => '0.25',
                'cost_currency' => 'NOK',
                'primary_checksum' => hash('sha256', 'primary'),
                'secondary_checksum' => hash('sha256', 'secondary'),
            ],
        ], $first, $candidate);
        $this->repair($import, 3, PurchaseOrderImportRepair::STATUS_PROPOSAL_ONLY_STATE_CHANGED, $blocked, [
            'method' => 'ai',
            'diagnosis' => 'A concurrent import update was detected.',
            'before_document' => $current,
            'blocked_reason' => 'repair_state_changed_during_ai',
            'candidate_reproduction' => null,
        ], $current);

        $response = $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertSee('Repair Audit History')
            ->assertSee('Superseded')
            ->assertSee('Applied')
            ->assertSee('Blocked')
            ->assertSee('Before / After Diff')
            ->assertSee('ORDER-FIRST')
            ->assertSee('ORDER-CURRENT')
            ->assertSee('Verified Evidence')
            ->assertSee('Verified current repair evidence')
            ->assertSee('Candidate reproduction: current 1')
            ->assertSee('protected fixtures 2')
            ->assertSee('historical 3')
            ->assertSee('AI Governance, Budget, and Consensus')
            ->assertSee('5.00 NOK')
            ->assertSee('1.25 / 3.75 NOK')
            ->assertSee('supplier-order-primary / 1.00 NOK')
            ->assertSee('Matched / 0.25 NOK')
            ->assertSee('within_budget')
            ->assertSee('repair_state_changed_during_ai')
            ->assertSee('Reprocess Current Applied Repair')
            ->assertSee('Open Candidate Version')
            ->assertSee('Exact before snapshot unavailable for this legacy repair')
            ->assertDontSee('SECRET DELIVERY ADDRESS')
            ->assertDontSee('RAW-PROMPT-SECRET')
            ->assertDontSee('Apply Proposal');

        $this->assertSame(1, substr_count($response->getContent(), 'Reprocess Current Applied Repair'));
        $this->assertStringContainsString(
            '#profile-version-'.$candidate->id,
            $response->getContent(),
        );
    }

    /** @return array{0: PurchaseOrderImportProfile, 1: PurchaseOrderImportProfileVersion} */
    private function profileAndVersion(string $slug): array
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $profile = PurchaseOrderImportProfile::query()->create([
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'description' => 'Supplier profile test metadata.',
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
            'priority' => 10,
            'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
            'policy_overrides' => [],
            'health_state' => 'healthy',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $version = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 1,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
            'created_by' => $this->actor->id,
        ]);
        $profile->forceFill(['active_version_id' => $version->id])->save();

        return [$profile->fresh(), $version->fresh()];
    }

    private function supplierImport(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        array $document,
    ): PurchaseOrderImport {
        $source = [
            'source' => 'email',
            'subject' => 'Display-safe supplier confirmation',
            'from' => ['email' => 'orders@example.invalid'],
            'body_html' => '',
            'body_text' => 'Bounded supplier confirmation source.',
            'attachments' => [],
        ];

        return PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'supplier-order-history-test',
            'source_id' => 'repair-history',
            'source_action_hash' => hash('sha256', 'repair-history-action'),
            'source_fingerprint' => StableJson::checksum($source),
            'safe_source_snapshot' => $source,
            'trusted_auth_snapshot' => [
                'authentication_passed' => true,
                'aligned' => true,
            ],
            'profile_id' => $profile->id,
            'profile_version_id' => $version->id,
            'external_order_number' => $document['external_order_number'],
            'normalized_document' => $document,
            'validation_results' => ['valid' => true, 'errors' => [], 'warnings' => []],
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'stage' => PurchaseOrderImport::STAGE_VALIDATE,
            'attempt_count' => 0,
            'requested_by' => $this->actor->id,
        ]);
    }

    private function repair(
        PurchaseOrderImport $import,
        int $sequence,
        string $status,
        array $corrected,
        array $decision,
        array $before,
        ?PurchaseOrderImportProfileVersion $candidate = null,
    ): PurchaseOrderImportRepair {
        return $import->repairs()->create([
            'sequence' => $sequence,
            'ai_execution_uuid' => sprintf('00000000-0000-4000-8000-%012d', $sequence),
            'status' => $status,
            'original_document_checksum' => StableJson::checksum($before),
            'corrected_document' => $corrected,
            'corrected_document_checksum' => StableJson::checksum($corrected),
            'profile_candidate_version_id' => $candidate?->id,
            'validation_results' => [
                'valid' => true,
                'errors' => [],
                'warnings' => $sequence === 2 ? [[
                    'code' => 'human_review_recommended',
                    'path' => 'external_order_number',
                    'message' => 'Human review remains recommended.',
                ]] : [],
                'confidence_dimensions' => ['extraction_evidence' => 100],
            ],
            'decision_summary' => $decision,
            'actor_id' => $this->actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function document(string $externalOrder, string $quote): array
    {
        $anchor = [
            'block_id' => 'b0001',
            'row_id' => null,
            'column' => null,
            'quote' => $quote,
        ];

        return [
            'supplier' => ['name' => 'History Supplier'],
            'external_order_number' => $externalOrder,
            'ordered_at' => '2026-08-05',
            'currency' => 'NOK',
            'delivery' => ['method' => 'Parcel', 'address' => null, 'expected_at' => '2026-08-12'],
            'lines' => [[
                'supplier_sku' => 'SKU-100',
                'description' => 'Repair history item',
                'quantity' => '2',
                'unit_price' => '100.00',
                'line_total' => '200.00',
                'tax_rate' => null,
                'currency' => 'NOK',
                'evidence' => [
                    'supplier_sku' => $anchor,
                    'quantity' => $anchor,
                ],
            ]],
            'totals' => [
                'goods_subtotal' => '200.00',
                'freight' => '0.00',
                'discount' => '0.00',
                'other_charges' => '0.00',
                'tax_total' => null,
                'total_ex_tax' => '200.00',
                'total_inc_tax' => null,
            ],
            'evidence' => [
                'external_order_number' => $anchor,
            ],
        ];
    }
}
