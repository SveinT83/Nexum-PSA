<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;

class CloneSupplierOrderProfileVersion
{
    public function __construct(private CreateSupplierOrderProfileVersion $createVersion) {}

    /** @param array<string, mixed> $candidateDefinition */
    public function handle(
        PurchaseOrderImportProfileVersion $sourceVersion,
        array $candidateDefinition,
        ?User $actor = null,
    ): PurchaseOrderImportProfileVersion {
        return $this->createVersion->handle(
            profile: PurchaseOrderImportProfile::query()->findOrFail($sourceVersion->profile_id),
            definition: $candidateDefinition,
            source: 'clone',
            actor: $actor,
            parent: $sourceVersion,
        );
    }
}
