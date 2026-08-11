<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Illuminate\Validation\ValidationException;

class ExportSupplierOrderProfile
{
    public function __construct(private SupplierOrderProfileDefinitionValidator $definitionValidator) {}

    /** @return array<string, mixed> */
    public function handle(
        PurchaseOrderImportProfile $profile,
        ?PurchaseOrderImportProfileVersion $version = null,
    ): array {
        $version ??= $profile->activeVersion()->first()
            ?? $profile->versions()->first();

        if ($version === null || (int) $version->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'version' => 'An exportable version belonging to the supplier profile is required.',
            ]);
        }

        $sourceDefinition = (array) $version->definition;
        $this->definitionValidator->validateOrFail($sourceDefinition);
        if (! hash_equals(StableJson::checksum($sourceDefinition), (string) $version->checksum)) {
            throw ValidationException::withMessages([
                'version' => 'Supplier profile cannot be exported because its checksum is invalid.',
            ]);
        }
        $definition = $this->portableDefinition($sourceDefinition);
        $this->definitionValidator->validateOrFail($definition);
        $checksum = StableJson::checksum($definition);

        return [
            'schema_version' => 'storage.supplier_order_profile_export.v1',
            'profile' => [
                'name' => $profile->name,
                'slug' => $profile->slug,
                'description' => $profile->description,
                'priority' => (int) $profile->priority,
                'matching_scope' => (array) ($definition['match'] ?? []),
                'policy_overrides' => (array) ($profile->policy_overrides ?? []),
            ],
            'version' => [
                'version_number' => (int) $version->version_number,
                'schema_version' => $version->schema_version,
                'definition' => $definition,
                'checksum' => $checksum,
                'source' => $version->source,
            ],
        ];
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function portableDefinition(array $definition): array
    {
        $match = is_array($definition['match'] ?? null) ? $definition['match'] : [];
        $definition['match'] = [
            'account_ids' => [],
            'mailboxes' => [],
            'recipients' => ['configure-local-routing@example.invalid'],
            'senders' => [],
            'sender_domains' => array_values(array_filter(
                (array) ($match['sender_domains'] ?? []),
                'is_string',
            )),
            'subject_markers' => array_values(array_filter(
                (array) ($match['subject_markers'] ?? []),
                'is_string',
            )),
            'body_markers' => array_values(array_filter(
                (array) ($match['body_markers'] ?? []),
                'is_string',
            )),
            'authenticated_supplier_domains' => array_values(array_filter(
                (array) ($match['authenticated_supplier_domains'] ?? []),
                'is_string',
            )),
            'require_trusted_auth' => (bool) ($match['require_trusted_auth'] ?? true),
            'require_aligned' => (bool) ($match['require_aligned'] ?? true),
        ];
        if (is_array($definition['defaults'] ?? null)) {
            $definition['defaults']['warehouse_id'] = null;
        }

        return $definition;
    }
}
