<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateSupplierOrderProfileMetadata
{
    private const MUTABLE_FIELDS = [
        'name',
        'slug',
        'description',
        'matching_scope',
    ];

    public function __construct(
        private readonly SupplierOrderProfileDefinitionValidator $definitionValidator,
    ) {}

    /**
     * Update only mutable profile-container metadata and append an immutable
     * audit row. Parser definitions and version checksums are never written.
     *
     * @param  array<string, mixed>  $data
     */
    public function handle(PurchaseOrderImportProfile $profile, array $data, User $actor): PurchaseOrderImportProfile
    {
        if (! $actor->isActive() || ! $actor->can('storage.purchase_import_profile_manage')) {
            throw ValidationException::withMessages([
                'profile' => 'You are not allowed to manage supplier-order profiles.',
            ]);
        }

        return DB::transaction(function () use ($profile, $data, $actor): PurchaseOrderImportProfile {
            $lockedProfile = PurchaseOrderImportProfile::query()
                ->with('activeVersion')
                ->lockForUpdate()
                ->findOrFail($profile->id);
            $prepared = [
                ...$data,
                'name' => is_string($data['name'] ?? null) ? trim($data['name']) : ($data['name'] ?? null),
                'slug' => is_string($data['slug'] ?? null) ? trim($data['slug']) : ($data['slug'] ?? null),
                'description' => is_string($data['description'] ?? null)
                    ? trim($data['description'])
                    : ($data['description'] ?? null),
                'reason' => is_string($data['reason'] ?? null) ? trim($data['reason']) : ($data['reason'] ?? null),
            ];
            $validated = Validator::make($prepared, [
                'name' => ['required', 'string', 'max:255'],
                'slug' => [
                    'required',
                    'string',
                    'max:255',
                    'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                    Rule::unique('storage_purchase_order_import_profiles', 'slug')->ignore($lockedProfile->id),
                ],
                'description' => ['nullable', 'string', 'max:2000'],
                'matching_scope' => ['required', 'array'],
                'reason' => ['required', 'string', 'min:5', 'max:245'],
            ])->validate();

            $scopeValidation = $this->definitionValidator->validateMatchingScope(
                (array) $validated['matching_scope'],
            );
            if (! $scopeValidation->valid()) {
                throw ValidationException::withMessages([
                    'matching_scope' => collect($scopeValidation->errors)
                        ->map(fn (array $error): string => $error['path'].': '.$error['message'])
                        ->all(),
                ]);
            }

            $before = $this->snapshot($lockedProfile);
            $after = [
                ...$before,
                'name' => trim($validated['name']),
                'slug' => trim($validated['slug']),
                'description' => filled($validated['description'] ?? null)
                    ? trim($validated['description'])
                    : null,
                'matching_scope' => (array) $validated['matching_scope'],
            ];
            $changedFields = collect(self::MUTABLE_FIELDS)
                ->filter(fn (string $field): bool => ! $this->valuesEqual($before[$field], $after[$field]))
                ->values()
                ->all();

            if ($changedFields === []) {
                throw ValidationException::withMessages([
                    'profile' => 'At least one profile metadata field must change.',
                ]);
            }

            $lockedProfile->forceFill([
                'name' => $after['name'],
                'slug' => $after['slug'],
                'description' => $after['description'],
                'matching_scope' => $after['matching_scope'],
                'updated_by' => $actor->id,
            ])->save();

            $lockedProfile->metadataAudits()->create([
                'actor_id' => $actor->id,
                'changed_fields' => $changedFields,
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'reason' => trim($validated['reason']),
            ]);

            return $lockedProfile->fresh(['activeVersion', 'metadataAudits.actor']);
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(PurchaseOrderImportProfile $profile): array
    {
        return [
            'name' => (string) $profile->name,
            'slug' => (string) $profile->slug,
            'description' => $profile->description,
            'matching_scope' => (array) ($profile->matching_scope ?? []),
            'active_version_id' => $profile->active_version_id,
            'active_version_checksum' => $profile->activeVersion?->checksum,
        ];
    }

    private function valuesEqual(mixed $before, mixed $after): bool
    {
        if (is_array($before) || is_array($after)) {
            return StableJson::checksum($before) === StableJson::checksum($after);
        }

        return $before === $after;
    }
}
