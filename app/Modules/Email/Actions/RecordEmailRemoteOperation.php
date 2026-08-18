<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Email\Services\EmailRemoteOperationEvidenceSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RecordEmailRemoteOperation
{
    public function __construct(
        private readonly EmailRemoteOperationEvidenceSanitizer $evidenceSanitizer,
        private readonly EmailAccountProviderRuntimeResolver $runtimeResolver,
        private readonly EmailProviderRemoteOperationObserver $remoteOperationObserver,
    ) {}

    public function pending(
        EmailAccount $account,
        string $operationType,
        string $idempotencyKey,
        ?User $actor = null,
        ?EmailFolder $folder = null,
        ?EmailMailboxPlacement $placement = null,
        array $request = [],
        ?EmailRemoteOperation $inverseOf = null,
    ): EmailRemoteOperation {
        if ($placement) {
            return DB::transaction(function () use (
                $account,
                $operationType,
                $idempotencyKey,
                $actor,
                $folder,
                $placement,
                $request,
                $inverseOf,
            ): EmailRemoteOperation {
                /** @var EmailMailboxPlacement|null $lockedPlacement */
                $lockedPlacement = EmailMailboxPlacement::query()
                    ->lockForUpdate()
                    ->find($placement->id);

                if (! $lockedPlacement
                    || (int) $lockedPlacement->account_id !== (int) $account->id
                    || ($folder && (int) $lockedPlacement->email_folder_id !== (int) $folder->id)) {
                    throw ValidationException::withMessages([
                        'placement' => 'This mailbox placement is no longer available for a provider operation.',
                    ]);
                }

                $providerBindingVersion = $this->runtimeResolver->captureBindingVersion($account);
                [$effectiveKey, $existing] = $this->effectiveOperation(
                    $idempotencyKey,
                    $providerBindingVersion,
                );

                if ($existing) {
                    $this->assertSamePlacementOperation(
                        $existing,
                        $account,
                        $operationType,
                        $folder,
                        $lockedPlacement,
                        $inverseOf,
                    );
                    $operation = $existing;
                } else {
                    $this->assertPlacementCanReserveOperation($lockedPlacement, $request);
                    $operation = $this->recordPending(
                        $account,
                        $operationType,
                        $effectiveKey,
                        $providerBindingVersion,
                        $actor,
                        $folder,
                        $lockedPlacement,
                        $request,
                        $inverseOf,
                    );
                    $this->assertSamePlacementOperation(
                        $operation,
                        $account,
                        $operationType,
                        $folder,
                        $lockedPlacement,
                        $inverseOf,
                    );
                }

                if ($this->remoteOperationObserver->hasCompetingUnresolvedForPlacement(
                    (int) $operation->email_mailbox_placement_id,
                    (int) $operation->id,
                )) {
                    throw ValidationException::withMessages([
                        'placement' => 'Another provider mailbox operation is still unresolved for this placement.',
                    ]);
                }

                return $operation;
            });
        }

        $providerBindingVersion = $this->runtimeResolver->captureBindingVersion($account);

        return $this->recordPending(
            $account,
            $operationType,
            $idempotencyKey,
            $providerBindingVersion,
            $actor,
            $folder,
            null,
            $request,
            $inverseOf,
        );
    }

    private function recordPending(
        EmailAccount $account,
        string $operationType,
        string $idempotencyKey,
        int $providerBindingVersion,
        ?User $actor,
        ?EmailFolder $folder,
        ?EmailMailboxPlacement $placement,
        array $request,
        ?EmailRemoteOperation $inverseOf,
    ): EmailRemoteOperation {
        $sanitizedRequest = $this->evidenceSanitizer->sanitize($request);

        $attributes = [
            'account_id' => $account->id,
            'email_folder_id' => $folder?->id,
            'email_mailbox_placement_id' => $placement?->id,
            'requested_by' => $actor?->id,
            'provider' => $request['provider'] ?? 'imap',
            'operation_type' => $operationType,
            'status' => EmailRemoteOperation::STATUS_PENDING,
            'source_folder_path' => $request['source_folder_path'] ?? $folder?->path,
            'target_folder_path' => $request['target_folder_path'] ?? null,
            'request_json' => $sanitizedRequest,
            'expected_placement_sync_version' => $placement?->sync_version,
            'expected_provider_uid' => $placement?->imap_uid,
            'expected_uid_validity' => $placement?->imap_uid_validity,
            'expected_folder_updated_at' => $folder?->updated_at,
            'max_attempts' => EmailRemoteOperation::DEFAULT_MAX_ATTEMPTS,
        ];

        if (Schema::hasColumn('email_remote_operations', 'provider_binding_version')) {
            $attributes['provider_binding_version'] = $providerBindingVersion;
        }

        if (Schema::hasColumn('email_remote_operations', 'inverse_of_email_remote_operation_id')) {
            $attributes['inverse_of_email_remote_operation_id'] = $inverseOf?->id;
        }

        return EmailRemoteOperation::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            $attributes,
        );
    }

    /**
     * Resolve the same binding-specific idempotency row as the legacy
     * first-or-create flow before reserving any new provider operation.
     *
     * @return array{0: string, 1: EmailRemoteOperation|null}
     */
    private function effectiveOperation(string $idempotencyKey, int $providerBindingVersion): array
    {
        $base = EmailRemoteOperation::query()
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if (! Schema::hasColumn('email_remote_operations', 'provider_binding_version')
            || ! $base
            || (int) $base->provider_binding_version === $providerBindingVersion) {
            return [$idempotencyKey, $base];
        }

        $bindingKey = substr($idempotencyKey, 0, 135).':binding:'.$providerBindingVersion;

        return [
            $bindingKey,
            EmailRemoteOperation::query()
                ->where('idempotency_key', $bindingKey)
                ->lockForUpdate()
                ->first(),
        ];
    }

    /**
     * New placement operations are serialized by the placement row. Existing
     * effective idempotency rows remain returnable so their ordinary retry or
     * read-only reconciliation path can make the final provider decision.
     *
     * @param  array<string, mixed>  $request
     */
    private function assertPlacementCanReserveOperation(
        EmailMailboxPlacement $placement,
        array $request,
    ): void {
        if ($placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
            || $placement->provider_missing_at !== null) {
            throw ValidationException::withMessages([
                'placement' => 'This mailbox placement is no longer available at the provider.',
            ]);
        }

        $requestEvidence = [
            'source_folder_path' => (string) $placement->folder_path,
            'placement_sync_version' => (int) $placement->sync_version,
            'placement_imap_uid' => (int) $placement->imap_uid,
            'placement_uid_validity' => (int) $placement->imap_uid_validity,
        ];

        foreach ($requestEvidence as $key => $expected) {
            if (array_key_exists($key, $request) && $request[$key] !== $expected) {
                throw ValidationException::withMessages([
                    'placement' => 'This mailbox placement changed before the provider operation was reserved.',
                ]);
            }
        }
    }

    private function assertSamePlacementOperation(
        EmailRemoteOperation $operation,
        EmailAccount $account,
        string $operationType,
        ?EmailFolder $folder,
        EmailMailboxPlacement $placement,
        ?EmailRemoteOperation $inverseOf,
    ): void {
        if ((int) $operation->account_id !== (int) $account->id
            || (int) $operation->email_mailbox_placement_id !== (int) $placement->id
            || (int) $operation->email_folder_id !== (int) ($folder?->id ?? 0)
            || $operation->operation_type !== $operationType
            || (int) ($operation->inverse_of_email_remote_operation_id ?? 0) !== (int) ($inverseOf?->id ?? 0)) {
            throw ValidationException::withMessages([
                'placement' => 'The deterministic provider-operation reference is already in use.',
            ]);
        }
    }
}
