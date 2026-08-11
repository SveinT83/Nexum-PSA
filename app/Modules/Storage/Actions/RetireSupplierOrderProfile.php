<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetireSupplierOrderProfile
{
    public function handle(
        PurchaseOrderImportProfile $profile,
        string $reason,
        ?User $actor = null,
    ): PurchaseOrderImportProfile {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => 'Retiring a profile requires a reason of at most 255 characters.',
            ]);
        }

        return DB::transaction(function () use ($profile, $reason, $actor): PurchaseOrderImportProfile {
            $locked = PurchaseOrderImportProfile::query()->lockForUpdate()->findOrFail($profile->id);
            $locked->forceFill([
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_RETIRED,
                'health_state' => 'retired',
                'paused_at' => now(),
                'pause_reason' => $reason,
                'updated_by' => $actor?->id,
            ])->save();

            return $locked->fresh();
        });
    }
}
