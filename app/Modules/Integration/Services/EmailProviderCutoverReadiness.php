<?php

namespace App\Modules\Integration\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailCursorRebaselineRun;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailProviderDeletionCleanupAttempt;
use App\Modules\Email\Models\EmailProviderInventoryRun;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailSentReconciliation;
use App\Modules\Email\Support\EmailProviderIdlePresenceLease;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class EmailProviderCutoverReadiness
{
    public function __construct(private readonly EmailProviderLegacyAccountMaterial $legacy) {}

    public function blockCode(
        #[\SensitiveParameter] EmailAccount $account,
        #[\SensitiveParameter] EmailProviderMigrationItem $stagedItem,
    ): ?string {
        if ((string) $account->provider_credential_source !== 'legacy'
            || filled($account->provider_integration_id)) {
            return 'account_source_changed';
        }

        if (! hash_equals((string) $stagedItem->legacy_fingerprint, $this->legacy->legacyFingerprint($account))) {
            return 'legacy_snapshot_stale';
        }

        $connection = EmailProviderConnection::query()
            ->with('activeCredentialVersion')
            ->find($stagedItem->provider_integration_id);
        $credential = $connection?->activeCredentialVersion;

        if (! $connection
            || ! $credential
            || (int) $credential->id !== (int) $stagedItem->credential_version_id
            || $connection->status !== 'active'
            || $credential->state !== EmailProviderCredentialVersion::STATE_ACTIVE
            || (int) $connection->configuration_version !== (int) $stagedItem->staged_configuration_version
            || (int) $connection->verified_configuration_version !== (int) $connection->configuration_version
            || (int) $connection->verified_credential_version !== (int) $stagedItem->staged_credential_version
            || (int) $credential->version !== (int) $stagedItem->staged_credential_version
            || (int) $credential->verified_configuration_version !== (int) $connection->configuration_version
            || ! $credential->verified_at
            || ! $credential->hasCiphertext()) {
            return 'provider_version_not_ready';
        }

        if (! $account->provider_runtime_paused_at || ! $account->provider_runtime_drained_at) {
            return 'provider_work_not_paused_drained';
        }

        if (Carbon::parse((string) $account->provider_runtime_drained_at)
            ->lt(Carbon::parse((string) $account->provider_runtime_paused_at))) {
            return 'provider_work_drain_stale';
        }

        if (EmailProviderIdlePresenceLease::active((int) $account->id)) {
            return 'provider_idle_listener_active';
        }

        if (Schema::hasTable('email_provider_reconciliation_runs')
            && EmailProviderReconciliationRun::accountHasActiveRun((int) $account->id)) {
            return 'provider_reconciliation_active';
        }

        if (EmailRemoteOperation::query()
            ->where('account_id', $account->id)
            ->whereIn('status', [
                EmailRemoteOperation::STATUS_PENDING,
                EmailRemoteOperation::STATUS_RUNNING,
                EmailRemoteOperation::STATUS_FAILED,
            ])
            ->exists()) {
            return 'provider_operation_unresolved';
        }

        if ($this->hasUnresolvedProviderWork($account)) {
            return 'provider_binding_work_unresolved';
        }

        return null;
    }

    public function hasUnresolvedProviderWork(
        #[\SensitiveParameter] EmailAccount $account,
    ): bool {
        if (Schema::hasTable('email_composer_drafts')
            && EmailComposerDraft::query()
                ->where('email_account_id', $account->id)
                ->where('status', EmailComposerDraft::STATUS_ACTIVE)
                ->where(function ($drafts): void {
                    $drafts
                        ->whereIn('provider_draft_status', [
                            EmailComposerDraft::PROVIDER_DRAFT_PENDING,
                            EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
                            EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
                            EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
                            EmailComposerDraft::PROVIDER_DRAFT_ERROR,
                        ])
                        ->orWhereNotNull('provider_draft_uid')
                        ->orWhereNotNull('provider_draft_folder_path');
                })
                ->exists()) {
            return true;
        }

        if (Schema::hasTable('email_sent_reconciliations')
            && EmailSentReconciliation::query()
                ->where('account_id', $account->id)
                ->where('status', '!=', EmailSentReconciliation::STATUS_RECONCILED)
                ->exists()) {
            return true;
        }

        if (Schema::hasTable('email_historical_import_runs')
            && EmailHistoricalImportRun::query()
                ->where('account_id', $account->id)
                ->whereIn('status', [
                    EmailHistoricalImportRun::STATUS_PREVIEWED,
                    EmailHistoricalImportRun::STATUS_QUEUED,
                    EmailHistoricalImportRun::STATUS_RUNNING,
                    EmailHistoricalImportRun::STATUS_CANCELLING,
                ])
                ->exists()) {
            return true;
        }

        if (Schema::hasTable('email_cursor_rebaseline_runs')
            && EmailCursorRebaselineRun::query()
                ->where('account_id', $account->id)
                ->where('status', EmailCursorRebaselineRun::STATUS_PREVIEWED)
                ->exists()) {
            return true;
        }

        if (Schema::hasTable('email_provider_inventory_runs')
            && EmailProviderInventoryRun::query()
                ->where('account_id', $account->id)
                ->where('status', EmailProviderInventoryRun::STATUS_RUNNING)
                ->exists()) {
            return true;
        }

        return Schema::hasTable('email_provider_deletion_cleanup_attempts')
            && EmailProviderDeletionCleanupAttempt::query()
                ->where('account_id', $account->id)
                ->where('status', EmailProviderDeletionCleanupAttempt::STATUS_CHECKING)
                ->exists();
    }
}
