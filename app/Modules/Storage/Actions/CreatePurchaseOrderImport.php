<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderSourceIntegrity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreatePurchaseOrderImport
{
    public function __construct(
        private readonly SupplierOrderSourceIntegrity $sourceIntegrity,
    ) {}

    /**
     * Create one durable import for a stable Signal action, or return the existing record.
     *
     * @param  array<string, mixed>  $data
     * @return array{import: PurchaseOrderImport, created: bool}
     */
    public function handle(array $data): array
    {
        $validated = Validator::make($data, [
            'source_domain' => ['required', 'string', 'max:64'],
            'source_type' => ['required', 'string', 'max:128'],
            'source_id' => ['nullable', 'string', 'max:191'],
            'email_message_id' => ['nullable', 'integer', 'exists:email_messages,id'],
            'signal_id' => ['nullable', 'integer', 'exists:signals,id'],
            'signal_rule_id' => ['nullable', 'integer', 'exists:signal_rules,id'],
            'signal_action_key' => ['required', 'string', 'max:255'],
            'source_fingerprint' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
            'safe_source_snapshot' => ['required', 'array'],
            'trusted_auth_snapshot' => ['nullable', 'array'],
            'profile_id' => ['nullable', 'integer', 'exists:storage_purchase_order_import_profiles,id'],
            'profile_version_id' => ['nullable', 'integer', 'exists:storage_purchase_order_import_profile_versions,id'],
            'policy_revision_id' => ['required', 'integer', 'exists:storage_purchase_order_automation_policy_revisions,id'],
            'status' => ['required', 'string'],
            'stage' => ['required', 'string'],
            'reason_code' => ['nullable', 'string', 'max:255'],
            'requested_by' => ['nullable', 'integer', 'exists:user_management,id'],
        ])->validate();

        $this->sourceIntegrity->validateOrFail(
            $validated['safe_source_snapshot'],
            $validated['source_fingerprint'],
            $validated['trusted_auth_snapshot'] ?? [],
        );

        $sourceActionHash = hash('sha256', StableJson::encode([
            'source_domain' => $validated['source_domain'],
            'source_type' => $validated['source_type'],
            'source_id' => $validated['source_id'] ?? null,
            'signal_action_key' => $validated['signal_action_key'],
        ]));

        return DB::transaction(function () use ($validated, $sourceActionHash): array {
            // createOrFirst uses the unique source-action hash as the final
            // concurrency guard when two Signal workers race on the same email.
            $import = PurchaseOrderImport::query()->firstOrCreate(
                ['source_action_hash' => $sourceActionHash],
                Arr::only($validated, [
                    'source_domain',
                    'source_type',
                    'source_id',
                    'email_message_id',
                    'signal_id',
                    'signal_rule_id',
                    'signal_action_key',
                    'source_fingerprint',
                    'safe_source_snapshot',
                    'trusted_auth_snapshot',
                    'profile_id',
                    'profile_version_id',
                    'policy_revision_id',
                    'status',
                    'stage',
                    'reason_code',
                    'requested_by',
                ]) + ['attempt_count' => 0],
            );

            return ['import' => $import, 'created' => $import->wasRecentlyCreated];
        });
    }
}
