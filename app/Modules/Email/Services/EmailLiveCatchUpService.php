<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class EmailLiveCatchUpService
{
    /**
     * Read one bounded user stream window. The caller independently refreshes
     * and reauthorizes only its current list/selection when refresh is true.
     *
     * @return array{
     *   from_version: string,
     *   to_version: string,
     *   authorization_epoch: string,
     *   global_authorization_generation: string,
     *   change_types: list<string>,
     *   conversation_ids: list<int>,
     *   placement_ids: list<int>,
     *   truncated: bool,
     *   refresh: bool,
     *   skip_render: bool,
     *   reason: string,
     *   applied_receipt: string
     * }
     */
    public function catchUp(
        User $user,
        string $clientVersion,
        string $clientAuthorizationEpoch,
        string $clientGlobalAuthorizationGeneration,
        bool $forceBoundedRefresh = false,
    ): array {
        $clientVersionValid = $this->validDecimal($clientVersion);
        $clientEpochValid = $this->validDecimal($clientAuthorizationEpoch);
        $clientGlobalValid = $this->validDecimal($clientGlobalAuthorizationGeneration);
        $fromVersion = $this->decimal($clientVersion);
        $clientEpoch = $this->decimal($clientAuthorizationEpoch);
        $clientGlobalGeneration = $this->decimal($clientGlobalAuthorizationGeneration);
        $globalGeneration = (int) (DB::table('email_live_global_authority_states')
            ->where('id', 1)
            ->value('authorization_generation') ?? 0);
        $access = DB::table('email_live_user_access_states')
            ->where('user_id', $user->id)
            ->first();
        $stream = EmailLiveProjectionStream::query()
            ->where('stream_type', EmailLiveProjectionStream::TYPE_USER)
            ->where('user_id', $user->id)
            ->first();
        $toVersion = (int) ($stream?->current_version ?? 0);
        $epoch = (int) ($access?->authorization_epoch ?? 0);
        $boundaryCrossed = $access?->next_boundary_at !== null
            && now()->greaterThanOrEqualTo(CarbonImmutable::parse($access->next_boundary_at));
        $recomputeOpen = ! $access || $access->recompute_status !== 'sealed';
        $invalidClientState = ! $clientVersionValid || ! $clientEpochValid || ! $clientGlobalValid;
        $authorityChanged = ! $clientEpochValid || ! $clientGlobalValid
            || $clientEpoch !== $epoch
            || $clientGlobalGeneration !== $globalGeneration;
        $impossibleVersion = $fromVersion > $toVersion;
        $pruned = $stream && $fromVersion + 1 < (int) $stream->oldest_retained_version;
        $limit = min(250, max(1, (int) config('email_live.catch_up_version_limit', 250)));

        $changes = collect();
        if ($stream && ! $impossibleVersion && ! $pruned) {
            $changes = EmailLiveProjectionChange::query()
                ->where('stream_id', $stream->id)
                ->where('version', '>', $fromVersion)
                ->orderBy('version')
                ->limit($limit + 1)
                ->get();
        }

        $overLimit = $changes->count() > $limit || $toVersion - $fromVersion > $limit;
        $window = $changes->take($limit);
        $gap = ! $impossibleVersion
            && ! $pruned
            && $fromVersion < $toVersion
            && ((int) ($window->first()?->version ?? 0) !== $fromVersion + 1
                || (int) ($window->last()?->version ?? 0) !== min($toVersion, $fromVersion + $limit));
        $lastBoundedRefreshAt = $access?->last_bounded_refresh_at
            ? CarbonImmutable::parse($access->last_bounded_refresh_at)
            : null;
        $boundedRefreshFresh = $lastBoundedRefreshAt !== null
            && $lastBoundedRefreshAt->greaterThan(now()->subSeconds(
                max(1, (int) config('email_live.connected_safety_seconds', 120)),
            ));
        $generic = $invalidClientState || $authorityChanged || $boundaryCrossed || $recomputeOpen
            || $impossibleVersion || $pruned || $overLimit || $gap;
        $truncated = $generic || $window->contains(
            fn (EmailLiveProjectionChange $change): bool => (bool) $change->truncated,
        );

        $types = $window->pluck('change_types_json')
            ->flatten()
            ->filter(fn (mixed $type): bool => is_string($type)
                && in_array($type, EmailLiveProjectionChange::ALLOWED_TYPES, true))
            ->when($generic, fn ($types) => $types->push(EmailLiveProjectionChange::TYPE_AUTHORIZATION))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $identifierLimit = min(50, max(1, (int) config('email_live.identifier_limit', 50)));
        [$conversationIds, $conversationIdsTruncated] = $generic
            ? [[], false]
            : $this->identifiers($window, 'conversation_ids_json', $identifierLimit);
        [$placementIds, $placementIdsTruncated] = $generic
            ? [[], false]
            : $this->identifiers($window, 'placement_ids_json', $identifierLimit);
        if ($conversationIdsTruncated || $placementIdsTruncated) {
            $truncated = true;
        }

        $refresh = $forceBoundedRefresh || ! $boundedRefreshFresh
            || $fromVersion !== $toVersion || $generic;
        $reason = match (true) {
            $invalidClientState => 'invalid_client_state',
            $impossibleVersion => 'impossible_version',
            $pruned => 'pruned_history',
            $overLimit => 'version_window_exceeded',
            $gap => 'version_gap',
            $boundaryCrossed => 'access_boundary_crossed',
            $recomputeOpen => 'access_recompute_open',
            $authorityChanged => 'authorization_changed',
            $forceBoundedRefresh => 'safety_refresh',
            $fromVersion !== $toVersion => 'stream_changed',
            ! $boundedRefreshFresh => 'bounded_refresh_stale',
            default => 'unchanged',
        };

        return [
            'from_version' => (string) $fromVersion,
            'to_version' => (string) $toVersion,
            'authorization_epoch' => (string) $epoch,
            'global_authorization_generation' => (string) $globalGeneration,
            'change_types' => $types,
            'conversation_ids' => $conversationIds,
            'placement_ids' => $placementIds,
            'truncated' => $truncated,
            'refresh' => $refresh,
            'skip_render' => ! $refresh,
            'reason' => $reason,
            'applied_receipt' => $this->receipt(
                $user,
                (string) $toVersion,
                (string) $epoch,
                (string) $globalGeneration,
            ),
        ];
    }

    /** Acknowledge only a server-signed version echoed after a prior response. */
    public function acknowledgeAppliedVersion(
        User $user,
        string $clientVersion,
        string $clientAuthorizationEpoch,
        string $clientGlobalAuthorizationGeneration,
        string $receipt,
    ): void {
        $version = $this->decimal($clientVersion);
        $epoch = $this->decimal($clientAuthorizationEpoch);
        $globalGeneration = $this->decimal($clientGlobalAuthorizationGeneration);
        if ($receipt === '' || ! hash_equals(
            $this->receipt(
                $user,
                (string) $version,
                (string) $epoch,
                (string) $globalGeneration,
            ),
            $receipt,
        )) {
            return;
        }

        DB::transaction(function () use ($epoch, $globalGeneration, $user, $version): void {
            $stream = EmailLiveProjectionStream::query()
                ->where('stream_type', EmailLiveProjectionStream::TYPE_USER)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $access = DB::table('email_live_user_access_states')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $currentGlobalGeneration = (int) DB::table('email_live_global_authority_states')
                ->where('id', 1)
                ->lockForUpdate()
                ->value('authorization_generation');
            $currentVersion = (int) ($stream?->current_version ?? 0);
            if (! $access
                || $version > $currentVersion
                || $epoch !== (int) $access->authorization_epoch
                || $globalGeneration !== $currentGlobalGeneration) {
                return;
            }

            if ($access->recompute_status === 'sealed') {
                DB::table('email_live_user_access_states')
                    ->where('id', $access->id)
                    ->update([
                        'last_bounded_refresh_at' => now(),
                        'updated_at' => now(),
                    ]);
            }

            if (! $stream || $version < 1) {
                return;
            }

            if ($version > (int) $stream->acknowledged_version) {
                $stream->update([
                    'acknowledged_version' => $version,
                    'acknowledged_at' => now(),
                ]);
            }

            EmailLiveProjectionChange::query()
                ->where('stream_id', $stream->id)
                ->where('publication_status', EmailLiveProjectionChange::STATUS_PUBLISHED)
                ->where('version', '<=', $version)
                ->orderBy('version')
                ->get()
                ->each(fn (EmailLiveProjectionChange $change) => $change->update([
                    'publication_status' => EmailLiveProjectionChange::STATUS_SEALED,
                    'published_at' => null,
                    'sealed_at' => now(),
                    'retention_ready_at' => now(),
                    'error_code' => null,
                ]));
        });
    }

    public function receipt(
        User $user,
        string $version,
        string $authorizationEpoch,
        string $globalAuthorizationGeneration,
    ): string {
        if (! $this->validDecimal($version)
            || ! $this->validDecimal($authorizationEpoch)
            || ! $this->validDecimal($globalAuthorizationGeneration)) {
            return '';
        }

        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true) ?: '';
        }

        return hash_hmac('sha256', implode(':', [
            'email-live-applied-v1',
            (string) $user->id,
            $version,
            $authorizationEpoch,
            $globalAuthorizationGeneration,
        ]), $key);
    }

    private function decimal(string $value): int
    {
        return preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1
            ? min(PHP_INT_MAX, (int) $value)
            : 0;
    }

    private function validDecimal(string $value): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1;
    }

    /** @return array{0: list<int>, 1: bool} */
    private function identifiers($changes, string $column, int $limit): array
    {
        $identifiers = $changes
            ->pluck($column)
            ->flatten()
            ->filter(fn (mixed $identifier): bool => is_int($identifier) && $identifier > 0)
            ->unique()
            ->sort()
            ->values();

        return [$identifiers->take($limit)->all(), $identifiers->count() > $limit];
    }
}
