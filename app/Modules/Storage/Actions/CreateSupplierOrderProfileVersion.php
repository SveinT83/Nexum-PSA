<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSupplierOrderProfileVersion
{
    public function __construct(private SupplierOrderProfileDefinitionValidator $definitionValidator) {}

    /** @param array<string, mixed> $definition */
    public function handle(
        PurchaseOrderImportProfile $profile,
        array $definition,
        string $source = 'manual',
        ?User $actor = null,
        ?PurchaseOrderImportProfileVersion $parent = null,
    ): PurchaseOrderImportProfileVersion {
        $this->definitionValidator->validateOrFail($definition);
        if (preg_match('/^[a-z0-9._-]{1,64}$/', $source) !== 1) {
            throw ValidationException::withMessages([
                'source' => 'Profile-version source must be a bounded machine identifier.',
            ]);
        }

        $checksum = StableJson::checksum($definition);

        return DB::transaction(function () use ($profile, $definition, $source, $actor, $parent, $checksum): PurchaseOrderImportProfileVersion {
            $lockedProfile = PurchaseOrderImportProfile::query()
                ->lockForUpdate()
                ->findOrFail($profile->id);

            if ($parent !== null && (int) $parent->profile_id !== (int) $lockedProfile->id) {
                throw ValidationException::withMessages([
                    'parent' => 'Parent version must belong to the same supplier profile.',
                ]);
            }

            $existing = PurchaseOrderImportProfileVersion::query()
                ->where('profile_id', $lockedProfile->id)
                ->where('checksum', $checksum)
                ->first();
            if ($existing !== null) {
                return $existing;
            }

            $currentNumber = (int) PurchaseOrderImportProfileVersion::query()
                ->where('profile_id', $lockedProfile->id)
                ->max('version_number');

            $version = PurchaseOrderImportProfileVersion::query()->create([
                'profile_id' => $lockedProfile->id,
                'version_number' => $currentNumber + 1,
                'parent_version_id' => $parent?->id,
                'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
                'status' => PurchaseOrderImportProfileVersion::STATUS_DRAFT,
                'definition' => $definition,
                'checksum' => $checksum,
                'source' => $source,
                'created_by' => $actor?->id,
            ]);

            if ($actor !== null) {
                $lockedProfile->forceFill(['updated_by' => $actor->id])->save();
            }

            return $version;
        });
    }
}
