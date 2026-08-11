<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use Illuminate\Validation\ValidationException;

class RollbackSupplierOrderProfileVersion
{
    public function __construct(private ActivateSupplierOrderProfileVersion $activateVersion) {}

    public function handle(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $targetVersion,
        User $actor,
        string $reason,
    ): PurchaseOrderImportProfile {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 245) {
            throw ValidationException::withMessages([
                'reason' => 'Rollback requires a reason of at most 245 characters.',
            ]);
        }
        if ((int) $targetVersion->profile_id !== (int) $profile->id) {
            throw ValidationException::withMessages([
                'version' => 'Rollback target must belong to the selected supplier profile.',
            ]);
        }

        return $this->activateVersion->handle(
            $targetVersion,
            $actor,
            'Rollback: '.$reason,
        );
    }
}
