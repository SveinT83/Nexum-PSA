<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;

class ImportSupplierOrderProfile
{
    public function __construct(
        private SupplierOrderProfileDefinitionValidator $definitionValidator,
        private CreateSupplierOrderProfileVersion $createVersion,
    ) {}

    /**
     * Import a strict portable profile as a draft. Activation remains a separate fixture-gated action.
     *
     * @param  array<string, mixed>  $export
     * @return array{profile: PurchaseOrderImportProfile, version: PurchaseOrderImportProfileVersion}
     */
    public function handle(
        array $export,
        ?User $actor = null,
        ?string $slug = null,
    ): array {
        $validated = Validator::make($export, [
            'schema_version' => ['required', 'in:storage.supplier_order_profile_export.v1'],
            'profile' => ['required', 'array:name,slug,description,priority,matching_scope,policy_overrides'],
            'profile.name' => ['required', 'string', 'max:255'],
            'profile.slug' => ['required', 'string', 'max:255'],
            'profile.description' => ['nullable', 'string', 'max:4000'],
            'profile.priority' => ['required', 'integer', 'min:0', 'max:1000000'],
            'profile.matching_scope' => ['present', 'array'],
            'profile.policy_overrides' => ['present', 'array'],
            'version' => ['required', 'array:version_number,schema_version,definition,checksum,source'],
            'version.schema_version' => ['required', 'in:'.SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION],
            'version.definition' => ['required', 'array'],
            'version.checksum' => ['required', 'string', 'size:64', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();

        $definition = (array) data_get($validated, 'version.definition');
        try {
            $metadataSize = strlen(StableJson::encode([
                'matching_scope' => data_get($validated, 'profile.matching_scope', []),
                'policy_overrides' => data_get($validated, 'profile.policy_overrides', []),
            ]));
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'profile' => 'Imported profile metadata must contain JSON-compatible values only.',
            ]);
        }
        if ($metadataSize > 32768) {
            throw ValidationException::withMessages([
                'profile' => 'Imported profile metadata exceeds the 32 KB safety limit.',
            ]);
        }

        $this->definitionValidator->validateOrFail($definition);
        if (! hash_equals(
            (string) data_get($validated, 'version.checksum'),
            StableJson::checksum($definition),
        )) {
            throw ValidationException::withMessages([
                'version.checksum' => 'Imported profile checksum does not match its definition.',
            ]);
        }

        $matchingScope = (array) data_get($validated, 'profile.matching_scope', []);
        if ($matchingScope !== (array) ($definition['match'] ?? [])) {
            throw ValidationException::withMessages([
                'profile.matching_scope' => 'Portable matching scope must exactly match the immutable version definition.',
            ]);
        }
        $this->definitionValidator->validateOrFail($definition);

        $requestedSlug = $slug ?? (string) data_get($validated, 'profile.slug');
        $normalizedSlug = Str::slug($requestedSlug);
        if ($normalizedSlug === '' || mb_strlen($normalizedSlug) > 255) {
            throw ValidationException::withMessages([
                'slug' => 'Imported profile slug is invalid.',
            ]);
        }

        return DB::transaction(function () use ($validated, $definition, $matchingScope, $normalizedSlug, $actor): array {
            if (PurchaseOrderImportProfile::query()->where('slug', $normalizedSlug)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages([
                    'slug' => 'A supplier profile with this slug already exists.',
                ]);
            }

            $profile = PurchaseOrderImportProfile::query()->create([
                'name' => data_get($validated, 'profile.name'),
                'slug' => $normalizedSlug,
                'description' => data_get($validated, 'profile.description'),
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
                'priority' => (int) data_get($validated, 'profile.priority'),
                'matching_scope' => $matchingScope,
                'policy_overrides' => (array) data_get($validated, 'profile.policy_overrides', []),
                'health_state' => 'unknown',
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $version = $this->createVersion->handle(
                profile: $profile,
                definition: $definition,
                source: 'import',
                actor: $actor,
            );

            return ['profile' => $profile->fresh(), 'version' => $version];
        });
    }
}
