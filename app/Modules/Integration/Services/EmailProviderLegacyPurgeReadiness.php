<?php

namespace App\Modules\Integration\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;

final class EmailProviderLegacyPurgeReadiness
{
    public function __construct(private readonly EmailProviderLegacyAccountMaterial $legacy) {}

    /**
     * Purge is intentionally unavailable in this slice. The result exposes
     * technical blockers plus the two mandatory future governance gates.
     *
     * @return array{ready: false, technical_conditions_met: bool, block_codes: list<string>}
     */
    public function evaluate(EmailAccount $account): array
    {
        $blocks = [];

        if ((string) $account->provider_credential_source !== 'integration') {
            $blocks[] = 'account_not_integration_owned';
        }

        if (! $this->legacy->isComplete($account)) {
            $blocks[] = 'legacy_material_not_intact';
        }

        $cutoverRunIds = EmailProviderMigrationItem::query()
            ->where('email_account_id', $account->id)
            ->whereNotNull('cutover_at')
            ->pluck('migration_run_id');

        if ($cutoverRunIds->isEmpty()) {
            $blocks[] = 'verified_cutover_not_found';
        } elseif (EmailProviderMigrationRun::query()
            ->whereIn('id', $cutoverRunIds)
            ->where(function ($query): void {
                $query->whereNull('rollback_deadline_at')
                    ->orWhere('rollback_deadline_at', '>', now());
            })->exists()) {
            $blocks[] = 'rollback_window_open';
        }

        $technicalConditionsMet = $blocks === [];
        $blocks[] = 'named_human_review_required';
        $blocks[] = 'backup_recovery_evidence_required';

        return [
            'ready' => false,
            'technical_conditions_met' => $technicalConditionsMet,
            'block_codes' => $blocks,
        ];
    }
}
