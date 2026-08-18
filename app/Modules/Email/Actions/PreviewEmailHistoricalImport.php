<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailHistoricalImportItem;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailHistoricalImportPolicy;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use App\Modules\Email\Services\EmailMailboxMaintenanceFingerprint;
use App\Modules\Email\Services\EmailMailboxMaintenanceLock;
use App\Modules\Email\Services\ImapClient;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PreviewEmailHistoricalImport
{
    protected int $expectedProviderBindingVersion = 1;

    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
        private readonly EmailMailboxMaintenanceFingerprint $fingerprints,
        private readonly EmailMailboxMaintenanceLock $locks,
        private readonly EmailHistoricalImportPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(EmailAccount $account, User $actor, array $input): EmailHistoricalImportRun
    {
        $this->authorization->authorize($actor, $account);
        $scope = $this->normalizeScope($input);
        $folders = EmailFolder::query()
            ->where('account_id', $account->id)
            ->whereIn('id', $scope['folder_ids'])
            ->orderBy('path')
            ->get();

        if ($folders->count() !== count($scope['folder_ids'])) {
            throw ValidationException::withMessages([
                'folders' => 'Choose enabled, selectable folders from this account.',
            ]);
        }
        // Provider UIDs are only ordered inside one UID namespace. Keep one
        // deterministic cross-folder order without fetching content-bearing
        // headers to pretend a global received-at chronology.
        $scope['folder_ids'] = $folders->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ($folders as $folder) {
            $this->authorization->authorizeFolder($actor, $account, $folder);
            if (! $this->authorization->hasCurrentNamespace($folder)
                || $folder->live_start_uid === null) {
                throw ValidationException::withMessages([
                    'folders' => 'Every selected folder needs a proven live UID namespace before historical import.',
                ]);
            }
        }

        $lock = $this->locks->acquire((int) $account->id);
        $this->expectedProviderBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
            ->captureBindingVersion($account);
        $client = $this->makeImapClient($account);

        try {
            $client->connect();
            [$snapshots, $candidates] = $this->providerPreview($client, $folders->all(), $scope);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'mailbox' => 'The provider mailbox could not be read for a safe historical preview.',
            ]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Read-only preview evidence remains valid after a failed best-effort disconnect.
            }
            $lock->release();
        }

        return DB::transaction(function () use ($account, $actor, $folders, $scope, $snapshots, $candidates): EmailHistoricalImportRun {
            $currentActor = User::query()->whereKey($actor->id)->first();
            $currentAccount = EmailAccount::query()->lockForUpdate()->find($account->id);

            if (! $currentActor || ! $currentAccount) {
                throw new AuthorizationException('Mailbox maintenance is unavailable.');
            }
            $this->authorization->authorize($currentActor, $currentAccount);
            if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($currentAccount)
                !== $this->expectedProviderBindingVersion) {
                throw ValidationException::withMessages([
                    'mailbox' => 'The mailbox provider binding changed during preview. Preview again.',
                ]);
            }

            foreach ($folders as $folder) {
                $locked = EmailFolder::query()
                    ->whereKey($folder->id)
                    ->where('account_id', $currentAccount->id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked
                    || (string) $locked->path !== (string) $folder->path
                    || (int) $locked->active_uid_namespace_id !== (int) $folder->active_uid_namespace_id
                    || (int) $locked->uid_validity !== (int) $folder->uid_validity
                    || (int) $locked->live_start_uid !== (int) $folder->live_start_uid) {
                    throw ValidationException::withMessages([
                        'mailbox' => 'The local folder baseline changed during preview. Preview again.',
                    ]);
                }
                $this->authorization->authorizeFolder($currentActor, $currentAccount, $locked);
            }

            $itemEvidence = collect($candidates)->map(fn (array $item): array => [
                'folder_id' => $item['email_folder_id'],
                'uid_namespace_id' => $item['uid_namespace_id'],
                'uid_validity' => $item['uid_validity'],
                'imap_uid' => $item['imap_uid'],
                'status' => $item['status'],
            ])->values()->all();
            $fingerprint = $this->fingerprints->make([
                'account_id' => (int) $currentAccount->id,
                'provider_binding_version' => $this->expectedProviderBindingVersion,
                'scope' => $scope,
                'provider_snapshot' => $snapshots,
                'items' => $itemEvidence,
            ]);

            $already = collect($candidates)->where('status', EmailHistoricalImportItem::STATUS_ALREADY_PRESENT)->count();
            $pending = count($candidates) - $already;
            $runAttributes = [
                'account_id' => $currentAccount->id,
                'requested_by' => $currentActor->id,
                'status' => EmailHistoricalImportRun::STATUS_PREVIEWED,
                'date_from' => $scope['date_from'],
                'date_to' => $scope['date_to'],
                'uid_from' => $scope['uid_from'],
                'uid_to' => $scope['uid_to'],
                'requested_cap' => $scope['requested_cap'],
                'effective_cap' => $scope['effective_cap'],
                'folder_scope_json' => collect($folders)->map(fn (EmailFolder $folder): array => [
                    'folder_id' => (int) $folder->id,
                    'path' => (string) $folder->path,
                    'uid_namespace_id' => (int) $folder->active_uid_namespace_id,
                    'uid_validity' => (int) $folder->uid_validity,
                    'live_start_uid' => (int) $folder->live_start_uid,
                ])->values()->all(),
                'provider_snapshot_json' => $snapshots,
                'preview_fingerprint' => $fingerprint,
                'idempotency_key' => hash('sha256', 'historical-preview:'.Str::uuid()),
                'matched_count' => count($candidates),
                'pending_count' => $pending,
                'already_present_count' => $already,
                'preview_expires_at' => now()->addMinutes(EmailHistoricalImportRun::PREVIEW_TTL_MINUTES),
            ];
            if (Schema::hasColumn('email_historical_import_runs', 'provider_binding_version')) {
                $runAttributes['provider_binding_version'] = $this->expectedProviderBindingVersion;
            }
            $run = EmailHistoricalImportRun::query()->create($runAttributes);

            foreach ($candidates as $item) {
                $run->items()->create($item);
            }

            return $run->load('items');
        });
    }

    /** @return array<string, mixed> */
    private function normalizeScope(array $input): array
    {
        $folderIds = collect($input['folder_ids'] ?? $input['folders'] ?? [])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($folderIds === []) {
            throw ValidationException::withMessages(['folders' => 'Choose at least one folder.']);
        }

        try {
            $dateFrom = CarbonImmutable::parse((string) ($input['date_from'] ?? ''), 'UTC')->startOfDay();
            $dateTo = CarbonImmutable::parse((string) ($input['date_to'] ?? ''), 'UTC')->startOfDay();
        } catch (Throwable) {
            throw ValidationException::withMessages(['date_from' => 'Choose a valid UTC date window.']);
        }

        if ($dateTo->lt($dateFrom)
            || $dateFrom->diffInDays($dateTo) + 1 > EmailHistoricalImportRun::MAX_DATE_WINDOW_DAYS) {
            throw ValidationException::withMessages([
                'date_to' => 'Historical import is limited to an inclusive 31-day UTC window.',
            ]);
        }

        $configuredCap = $this->policy->configuredCap();

        $requestedCap = array_key_exists('cap', $input)
            ? $input['cap']
            : min(EmailHistoricalImportRun::DEFAULT_CAP, $configuredCap);
        if (! is_numeric($requestedCap) || (int) $requestedCap < 1) {
            throw ValidationException::withMessages(['cap' => 'The import cap must be at least one message.']);
        }
        if ((int) $requestedCap > EmailHistoricalImportRun::HARD_CAP) {
            throw ValidationException::withMessages([
                'cap' => 'Historical import is limited to 500 messages per run.',
            ]);
        }
        if ((int) $requestedCap > $configuredCap) {
            throw ValidationException::withMessages([
                'cap' => 'The requested cap exceeds the configured historical import limit.',
            ]);
        }

        $uidFrom = $input['uid_from'] ?? 1;
        $uidTo = $input['uid_to'] ?? null;
        if (! is_numeric($uidFrom) || (int) $uidFrom < 1
            || ($uidTo !== null && (! is_numeric($uidTo) || (int) $uidTo < (int) $uidFrom))) {
            throw ValidationException::withMessages(['uid_from' => 'Choose a valid positive UID range.']);
        }

        return [
            'folder_ids' => $folderIds,
            'date_from' => $dateFrom->toDateString(),
            'date_to' => $dateTo->toDateString(),
            'uid_from' => (int) $uidFrom,
            'uid_to' => $uidTo === null ? null : (int) $uidTo,
            'requested_cap' => (int) $requestedCap,
            'effective_cap' => (int) $requestedCap,
        ];
    }

    /**
     * @param  array<int, EmailFolder>  $folders
     * @param  array<string, mixed>  $scope
     * @return array{0: array<int, array<string, int|string|null>>, 1: array<int, array<string, mixed>>}
     */
    private function providerPreview(ImapClient $client, array $folders, array $scope): array
    {
        // Collect one sentinel beyond the confirmed cap across the complete
        // ordered folder scope. A preview never silently truncates a wider
        // provider result and labels the partial scope complete.
        $remaining = (int) $scope['effective_cap'] + 1;
        $remainingUidScanBudget = ImapClient::HISTORICAL_UID_MAX_SCAN_SPAN;
        $snapshots = [];
        $items = [];
        $dateFrom = CarbonImmutable::parse($scope['date_from'], 'UTC');
        $dateTo = CarbonImmutable::parse($scope['date_to'], 'UTC');

        foreach ($folders as $folder) {
            $start = $this->normalizedProviderState($client->folderState($folder->path));
            if ($start['uid_validity'] <= 0 || $start['next_uid'] <= 0
                || $start['uid_validity'] !== (int) $folder->uid_validity) {
                throw ValidationException::withMessages([
                    'mailbox' => 'A selected folder no longer matches its proven UID namespace.',
                ]);
            }

            $uidTo = min(
                (int) $folder->live_start_uid,
                $start['next_uid'] - 1,
                $scope['uid_to'] ?? PHP_INT_MAX,
            );
            $folderScanSpan = $uidTo >= (int) $scope['uid_from']
                ? $uidTo - (int) $scope['uid_from'] + 1
                : 0;
            if ($folderScanSpan > $remainingUidScanBudget) {
                throw ValidationException::withMessages([
                    'uid_from' => 'Narrow the combined folder UID scope to at most 50,000 numeric UIDs.',
                ]);
            }
            $remainingUidScanBudget -= $folderScanSpan;
            $uids = $remaining > 0
                ? $client->searchHistoricalUidsInFolder(
                    $folder->path,
                    $dateFrom,
                    $dateTo,
                    (int) $scope['uid_from'],
                    $uidTo,
                    $remaining,
                )
                : [];
            $end = $this->normalizedProviderState($client->folderState($folder->path));

            if ($start !== $end) {
                throw ValidationException::withMessages([
                    'mailbox' => 'The provider folder changed during preview. Preview again.',
                ]);
            }

            $snapshots[] = [
                'folder_id' => (int) $folder->id,
                'uid_namespace_id' => (int) $folder->active_uid_namespace_id,
                'uid_validity' => $start['uid_validity'],
                'next_uid' => $start['next_uid'],
                'highest_modseq' => $start['highest_modseq'],
                'exists_count' => $start['exists_count'],
                'uid_from' => (int) $scope['uid_from'],
                'uid_to' => $uidTo,
                'matched_uids' => $uids,
            ];

            $existing = EmailMailboxPlacement::query()
                ->where('account_id', $folder->account_id)
                ->where('email_folder_id', $folder->id)
                ->where('uid_namespace_id', $folder->active_uid_namespace_id)
                ->whereIn('imap_uid', $uids)
                ->pluck('id', 'imap_uid');

            foreach ($uids as $uid) {
                $placementId = $existing->get($uid);
                $items[] = [
                    'email_folder_id' => $folder->id,
                    'uid_namespace_id' => $folder->active_uid_namespace_id,
                    'folder_path' => $folder->path,
                    'uid_validity' => $folder->uid_validity,
                    'imap_uid' => $uid,
                    'status' => $placementId
                        ? EmailHistoricalImportItem::STATUS_ALREADY_PRESENT
                        : EmailHistoricalImportItem::STATUS_PENDING,
                    'email_mailbox_placement_id' => $placementId,
                    'completed_at' => $placementId ? now() : null,
                ];
            }

            $remaining -= count($uids);

            if (count($items) > (int) $scope['effective_cap']) {
                throw ValidationException::withMessages([
                    'cap' => 'The selected scope contains more messages than the confirmed cap. Narrow the date, folder, or UID range.',
                ]);
            }
        }

        return [$snapshots, $items];
    }

    /** @return array{uid_validity: int, next_uid: int, highest_modseq: int|null, exists_count: int|null} */
    private function normalizedProviderState(array $state): array
    {
        return [
            'uid_validity' => (int) ($state['uid_validity'] ?? 0),
            'next_uid' => (int) ($state['next_uid'] ?? 0),
            'highest_modseq' => isset($state['highest_modseq']) ? (int) $state['highest_modseq'] : null,
            'exists_count' => isset($state['exists_count']) ? (int) $state['exists_count'] : null,
        ];
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $this->expectedProviderBindingVersion,
        ]);
    }
}
