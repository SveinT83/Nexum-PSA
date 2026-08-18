<?php

namespace App\Modules\Email\Queries;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCursorRebaselineRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;

class EmailMailboxMaintenanceQuery
{
    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
    ) {}

    /** @return array<string, mixed> */
    public function forAccount(EmailAccount $account, User $actor): array
    {
        $this->authorization->authorize($actor, $account);
        $reconciliationRuns = EmailProviderReconciliationRun::query()
            ->where('account_id', $account->id)
            ->with(['requester:id,name', 'cancelledBy:id,name'])
            ->latest('id')
            ->limit(20)
            ->get();
        $reconciliationDetailRun = $reconciliationRuns
            ->first(fn (EmailProviderReconciliationRun $run): bool => $run->active_slot !== null)
            ?? $reconciliationRuns->first();
        $reconciliationDetailRun?->load([
            'folders' => fn ($query) => $query
                ->select([
                    'id',
                    'email_provider_reconciliation_run_id',
                    'folder_path',
                    'status',
                    'next_uid',
                    'scan_through_uid',
                    'observed_count',
                    'import_count',
                    'missing_count',
                    'conflict_count',
                    'reason_code',
                    'last_progress_at',
                ])
                ->orderBy('folder_path'),
        ]);

        return [
            'account' => $account,
            'folders' => EmailFolder::query()
                ->where('account_id', $account->id)
                ->with('activeUidNamespace')
                ->orderBy('path')
                ->orderBy('id')
                ->paginate(
                    perPage: 100,
                    columns: ['*'],
                    pageName: 'folder_page',
                )
                ->withQueryString(),
            'historicalRuns' => EmailHistoricalImportRun::query()
                ->where('account_id', $account->id)
                ->with('requester:id,name')
                ->latest('id')
                ->limit(20)
                ->get(),
            'rebaselineRuns' => EmailCursorRebaselineRun::query()
                ->where('account_id', $account->id)
                ->with(['requester:id,name', 'folder:id,path,name'])
                ->latest('id')
                ->limit(20)
                ->get(),
            'reconciliationRuns' => $reconciliationRuns,
            'reconciliationDetailRun' => $reconciliationDetailRun,
        ];
    }
}
