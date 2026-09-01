<?php

namespace App\Modules\Integration\Actions;

use App\Modules\Integration\Models\RmmAlertRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteRmmAlertRule
{
    public function handle(RmmAlertRule $rule): void
    {
        DB::transaction(function () use ($rule): void {
            $current = RmmAlertRule::query()->lockForUpdate()->findOrFail($rule->id);
            if ($current->is_active) {
                throw ValidationException::withMessages([
                    'rule' => 'Disable the RMM Alert Rule before deleting it.',
                ]);
            }

            $current->delete();
        });
    }
}
