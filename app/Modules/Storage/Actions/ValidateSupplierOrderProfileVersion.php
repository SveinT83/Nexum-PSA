<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileValidationResult;
use Illuminate\Support\Facades\DB;

class ValidateSupplierOrderProfileVersion
{
    public function __construct(
        private SupplierOrderProfileDefinitionValidator $definitionValidator,
        private ReplaySupplierOrderProfileFixtures $replayFixtures,
    ) {}

    public function handle(
        PurchaseOrderImportProfileVersion $version,
    ): SupplierOrderProfileValidationResult {
        return DB::transaction(function () use ($version): SupplierOrderProfileValidationResult {
            $locked = PurchaseOrderImportProfileVersion::query()
                ->lockForUpdate()
                ->findOrFail($version->id);
            $definition = (array) $locked->definition;
            $validation = $this->definitionValidator->validate($definition);
            $errors = $validation->errors;
            $warnings = $validation->warnings;

            if (! hash_equals(StableJson::checksum($definition), (string) $locked->checksum)) {
                $errors[] = [
                    'code' => 'profile_checksum_mismatch',
                    'path' => 'profile_version.checksum',
                    'message' => 'The immutable profile checksum does not match its definition.',
                ];
            }

            $replay = null;
            if ($errors === []) {
                $replay = $this->replayFixtures->handle($locked, true);
                if (! $replay->allPassed()) {
                    $errors[] = [
                        'code' => 'fixture_replay_failed',
                        'path' => 'fixtures',
                        'message' => 'Every supplier-profile fixture must pass before validation.',
                    ];
                }
                if (! $replay->protectedPassed()) {
                    $errors[] = [
                        'code' => 'protected_fixture_gate_failed',
                        'path' => 'fixtures',
                        'message' => 'At least one protected fixture is required and every protected fixture must pass.',
                    ];
                }
            }

            $valid = $errors === [];
            $status = in_array($locked->status, [
                PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
                PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED,
            ], true)
                ? $locked->status
                : ($valid
                    ? PurchaseOrderImportProfileVersion::STATUS_VALIDATED
                    : PurchaseOrderImportProfileVersion::STATUS_REJECTED);
            $locked->forceFill([
                'status' => $status,
                'test_metrics' => [
                    'definition_valid' => $validation->valid(),
                    'fixture_total' => $replay?->total ?? 0,
                    'fixture_passed' => $replay?->passed ?? 0,
                    'fixture_failed' => $replay?->failed ?? 0,
                    'protected_total' => $replay?->protectedTotal ?? 0,
                    'protected_passed' => $replay?->protectedPassed ?? 0,
                    'validated_checksum' => StableJson::checksum($definition),
                ],
                'validated_at' => $valid ? now() : null,
            ])->save();

            return new SupplierOrderProfileValidationResult(
                errors: $errors,
                warnings: $warnings,
            );
        });
    }
}
