<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use Carbon\CarbonImmutable;

class EmailHistoricalImportSnapshotVerifier
{
    /**
     * Re-run the exact metadata-only scope represented by a preview. No
     * content FETCH and no unread/Seen criterion is permitted here.
     */
    public function verify(EmailHistoricalImportRun $run, ImapClient $client): bool
    {
        $folderScopes = collect($run->folder_scope_json ?? [])->keyBy('folder_id');
        $snapshots = collect($run->provider_snapshot_json ?? []);
        $remaining = (int) $run->effective_cap + 1;
        $remainingUidScanBudget = ImapClient::HISTORICAL_UID_MAX_SCAN_SPAN;
        $dateFrom = CarbonImmutable::parse($run->date_from->toDateString(), 'UTC');
        $dateTo = CarbonImmutable::parse($run->date_to->toDateString(), 'UTC');

        foreach ($snapshots as $snapshot) {
            $folderId = (int) ($snapshot['folder_id'] ?? 0);
            $scope = $folderScopes->get($folderId);
            $folder = EmailFolder::query()
                ->whereKey($folderId)
                ->where('account_id', $run->account_id)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->first();

            if (! is_array($scope) || ! $folder
                || (string) ($scope['path'] ?? '') !== (string) $folder->path
                || (int) $scope['uid_namespace_id'] !== (int) $folder->active_uid_namespace_id
                || (int) $scope['uid_validity'] !== (int) $folder->uid_validity
                || (int) $scope['live_start_uid'] !== (int) $folder->live_start_uid
                || ! EmailFolderUidNamespace::query()
                    ->whereKey($folder->active_uid_namespace_id)
                    ->where('account_id', $run->account_id)
                    ->where('email_folder_id', $folder->id)
                    ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                    ->where('uid_validity', $folder->uid_validity)
                    ->exists()) {
                return false;
            }

            $start = $this->state($client->folderState($folder->path));
            $expectedState = [
                'uid_validity' => (int) ($snapshot['uid_validity'] ?? 0),
                'next_uid' => (int) ($snapshot['next_uid'] ?? 0),
                'highest_modseq' => isset($snapshot['highest_modseq']) ? (int) $snapshot['highest_modseq'] : null,
                'exists_count' => isset($snapshot['exists_count']) ? (int) $snapshot['exists_count'] : null,
            ];

            if ($start !== $expectedState) {
                return false;
            }

            $uidTo = (int) ($snapshot['uid_to'] ?? 0);
            $folderScanSpan = $uidTo >= (int) $run->uid_from
                ? $uidTo - (int) $run->uid_from + 1
                : 0;
            if ($folderScanSpan > $remainingUidScanBudget) {
                return false;
            }
            $remainingUidScanBudget -= $folderScanSpan;

            $uids = $remaining > 0
                ? $client->searchHistoricalUidsInFolder(
                    $folder->path,
                    $dateFrom,
                    $dateTo,
                    (int) $run->uid_from,
                    $uidTo,
                    $remaining,
                )
                : [];
            $end = $this->state($client->folderState($folder->path));
            $expectedUids = array_map('intval', (array) ($snapshot['matched_uids'] ?? []));

            if ($end !== $start || $uids !== $expectedUids) {
                return false;
            }

            $remaining -= count($uids);
        }

        return $snapshots->count() === $folderScopes->count();
    }

    /** @return array{uid_validity: int, next_uid: int, highest_modseq: int|null, exists_count: int|null} */
    private function state(array $state): array
    {
        return [
            'uid_validity' => (int) ($state['uid_validity'] ?? 0),
            'next_uid' => (int) ($state['next_uid'] ?? 0),
            'highest_modseq' => isset($state['highest_modseq']) ? (int) $state['highest_modseq'] : null,
            'exists_count' => isset($state['exists_count']) ? (int) $state['exists_count'] : null,
        ];
    }
}
