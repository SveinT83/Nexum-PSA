<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivateSupplierOrderProfileVersion
{
    public function __construct(
        private SupplierOrderProfileDefinitionValidator $definitionValidator,
        private ReplaySupplierOrderProfileFixtures $replayFixtures,
    ) {}

    public function handle(
        PurchaseOrderImportProfileVersion $version,
        User $actor,
        string $reason,
    ): PurchaseOrderImportProfile {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages([
                'reason' => 'Activation requires a reason of at most 255 characters.',
            ]);
        }

        return DB::transaction(function () use ($version, $actor, $reason): PurchaseOrderImportProfile {
            // Every activation for one profile takes the same parent-first lock order.
            $profile = PurchaseOrderImportProfile::query()
                ->lockForUpdate()
                ->findOrFail($version->profile_id);
            $lockedVersion = PurchaseOrderImportProfileVersion::query()
                ->where('profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($version->id);

            if (! in_array($lockedVersion->status, [
                PurchaseOrderImportProfileVersion::STATUS_VALIDATED,
                PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED,
                PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            ], true)) {
                throw ValidationException::withMessages([
                    'version' => 'Only validated or previously active profile versions may be activated.',
                ]);
            }

            $definition = (array) $lockedVersion->definition;
            $definitionValidation = $this->definitionValidator->validate($definition);
            if (! $definitionValidation->valid()
                || ! hash_equals(StableJson::checksum($definition), (string) $lockedVersion->checksum)) {
                throw ValidationException::withMessages([
                    'version' => 'Profile definition or immutable checksum is no longer valid.',
                ]);
            }

            // The protected corpus is replayed while profile, version, and fixtures are locked.
            // A stale successful validation can therefore never bypass the activation gate.
            $replay = $this->replayFixtures->handle($lockedVersion, true);
            if (! $replay->allPassed() || ! $replay->protectedPassed()) {
                throw ValidationException::withMessages([
                    'fixtures' => 'Activation requires a fresh successful replay of every protected fixture.',
                ]);
            }

            if ($profile->active_version_id !== null
                && (int) $profile->active_version_id !== (int) $lockedVersion->id) {
                $previousVersion = PurchaseOrderImportProfileVersion::query()
                    ->lockForUpdate()
                    ->find($profile->active_version_id);
                if ($previousVersion?->status === PurchaseOrderImportProfileVersion::STATUS_ACTIVE) {
                    $previousVersion->forceFill([
                        'status' => PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED,
                    ])->save();
                }
            }

            $lockedVersion->forceFill([
                'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
                'activated_by' => $actor->id,
                'activated_at' => now(),
                'activation_reason' => $reason,
                'test_metrics' => [
                    ...(array) ($lockedVersion->test_metrics ?? []),
                    'activation_fixture_total' => $replay->total,
                    'activation_fixture_passed' => $replay->passed,
                    'activation_protected_total' => $replay->protectedTotal,
                    'activation_protected_passed' => $replay->protectedPassed,
                ],
            ])->save();

            $profile->forceFill([
                'active_version_id' => $lockedVersion->id,
                'matching_scope' => (array) ($definition['match'] ?? []),
                'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
                'health_state' => 'healthy',
                'consecutive_failures' => 0,
                'paused_at' => null,
                'pause_reason' => null,
                'updated_by' => $actor->id,
            ])->save();

            return $profile->fresh(['activeVersion']);
        });
    }
}
