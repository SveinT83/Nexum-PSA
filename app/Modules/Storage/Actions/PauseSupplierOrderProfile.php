<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PauseSupplierOrderProfile
{
    public function handle(
        PurchaseOrderImportProfile $profile,
        string $reason,
        ?User $actor = null,
    ): PurchaseOrderImportProfile {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => 'Pausing a profile requires a reason of at most 255 characters.',
            ]);
        }

        return DB::transaction(function () use ($profile, $reason, $actor): PurchaseOrderImportProfile {
            $locked = PurchaseOrderImportProfile::query()->lockForUpdate()->findOrFail($profile->id);
            if ($locked->lifecycle_state === PurchaseOrderImportProfile::STATE_RETIRED) {
                throw ValidationException::withMessages([
                    'profile' => 'A retired supplier profile cannot be paused.',
                ]);
            }

            $locked->forceFill([
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_PAUSED,
                'health_state' => 'paused',
                'paused_at' => now(),
                'pause_reason' => $reason,
                'updated_by' => $actor?->id,
            ])->save();

            return $locked->fresh();
        });
    }
}
