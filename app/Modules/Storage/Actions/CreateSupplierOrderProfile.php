<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Browser and API share one atomic profile/version creation contract. */
class CreateSupplierOrderProfile
{
    public function handle(array $data, User $actor): PurchaseOrderImportProfile
    {
        $createVersion = app(CreateSupplierOrderProfileVersion::class);
        if (($data['definition']['match'] ?? null) !== $data['matching_scope']) {
            throw ValidationException::withMessages([
                'matching_scope' => 'Matching scope must exactly match the definition match block.',
            ]);
        }

        $result = DB::transaction(function () use ($data, $actor, $createVersion): array {
            $profile = PurchaseOrderImportProfile::query()->create([
                'vendor_id' => $data['vendor_id'] ?? null,
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
                'priority' => $data['priority'],
                'matching_scope' => $data['matching_scope'],
                'policy_overrides' => $data['policy_overrides'] ?? [],
                'health_state' => 'unknown',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $version = $createVersion->handle(
                profile: $profile,
                definition: $data['definition'],
                source: 'manual',
                actor: $actor,
            );

            return compact('profile', 'version');
        });

        return $result['profile'];
    }
}
