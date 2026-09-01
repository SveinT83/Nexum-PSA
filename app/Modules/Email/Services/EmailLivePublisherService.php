<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Events\EmailProjectionInvalidated;
use App\Modules\Email\Jobs\EmailLivePublisher;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionDelivery;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class EmailLivePublisherService
{
    private const ERROR_APPEND = 'email_live_append_failed';

    private const ERROR_TRANSPORT = 'email_live_transport_failed';

    private const ERROR_CANDIDATE_PAGE = 'email_live_candidate_page_failed';

    private const ERROR_ATTEMPTS = 'email_live_attempts_exhausted';

    private const SUPPRESSED_UNAUTHORIZED = 'email_live_currently_unauthorized';

    private const SUPPRESSED_SOURCE_PATH = 'email_live_source_path_ineligible';

    /** Recover and advance a bounded amount of durable live work. */
    public function publishPending(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->recoverAbandonedClaims();

        EmailLiveProjectionChange::query()
            ->where('publication_status', EmailLiveProjectionChange::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(fn (EmailLiveProjectionChange $change) => $this->publish($change));

        $this->continuePublications();
        $this->continueDeliveries();
        $this->continueDeliverySummaries();
        app(EmailLiveRetentionService::class)->prune();
    }

    public function publish(EmailLiveProjectionChange $change): void
    {
        if (! $this->enabled()) {
            return;
        }

        $change = $change->fresh('stream');
        if (! $change?->stream) {
            return;
        }

        if ($change->stream->stream_type !== EmailLiveProjectionStream::TYPE_USER
            && $this->authorityDriftExists()) {
            return;
        }

        if ($change->publication_status === EmailLiveProjectionChange::STATUS_PENDING) {
            $change = $this->claimChange((int) $change->id);
        }

        if (! $change || $change->publication_status !== EmailLiveProjectionChange::STATUS_RUNNING) {
            return;
        }

        if ($change->stream->stream_type === EmailLiveProjectionStream::TYPE_USER) {
            $this->publishUserChange($change);

            return;
        }

        // Account/global publications were frozen atomically by the invalidator.
        // The source remains running until its delivery summary seals.
        if (! $change->publication()->exists()) {
            $this->failChange($change, self::ERROR_APPEND);
        }
    }

    private function authorityDriftExists(): bool
    {
        $generation = (int) (DB::table('email_live_global_authority_states')
            ->where('id', 1)
            ->value('authorization_generation') ?? 0);

        return DB::table('email_live_user_access_states')
            ->where('global_authorization_generation_seen', '<', $generation)
            ->limit(1)
            ->exists();
    }

    private function enabled(): bool
    {
        return app(EmailLiveRuntimeReadiness::class)->ready();
    }

    private function maxAttempts(): int
    {
        return min(3, max(1, (int) config('email_live.max_attempts', 3)));
    }

    private function pageSize(): int
    {
        return min(100, max(1, (int) config('email_live.publisher_page_size', 100)));
    }

    private function retryAt(): mixed
    {
        return now()->addSeconds(max(1, (int) config('email_live.retry_delay_seconds', 15)));
    }

    private function token(): string
    {
        return hash('sha256', (string) Str::uuid());
    }

    private function claimChange(int $changeId): ?EmailLiveProjectionChange
    {
        return DB::transaction(function () use ($changeId): ?EmailLiveProjectionChange {
            $change = EmailLiveProjectionChange::query()->lockForUpdate()->find($changeId);

            if (! $change
                || $change->publication_status !== EmailLiveProjectionChange::STATUS_PENDING
                || (int) $change->attempt_count >= $this->maxAttempts()
                || $change->available_at?->isFuture()
                || $change->next_attempt_at?->isFuture()) {
                return null;
            }

            $change->update([
                'publication_status' => EmailLiveProjectionChange::STATUS_RUNNING,
                'claim_token' => $this->token(),
                'attempt_count' => (int) $change->attempt_count + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'error_code' => null,
            ]);

            return $change->fresh('stream');
        });
    }

    private function publishUserChange(EmailLiveProjectionChange $change): void
    {
        $token = (string) $change->claim_token;

        try {
            $this->broadcast((int) $change->stream->user_id, $change);

            DB::transaction(function () use ($change, $token): void {
                $locked = EmailLiveProjectionChange::query()
                    ->whereKey($change->id)
                    ->where('publication_status', EmailLiveProjectionChange::STATUS_RUNNING)
                    ->where('claim_token', $token)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return;
                }

                $locked->update([
                    'publication_status' => EmailLiveProjectionChange::STATUS_PUBLISHED,
                    'claim_token' => null,
                    'published_at' => now(),
                    'next_attempt_at' => null,
                    'error_code' => null,
                ]);
            });
        } catch (Throwable) {
            $this->safeLog('user_transport_failed', $change->id);
            $this->failChange($change, self::ERROR_TRANSPORT, $token);
        }
    }

    private function failChange(
        EmailLiveProjectionChange $change,
        string $errorCode,
        ?string $claimToken = null,
    ): void {
        DB::transaction(function () use ($change, $claimToken, $errorCode): void {
            $locked = EmailLiveProjectionChange::query()
                ->whereKey($change->id)
                ->where('publication_status', EmailLiveProjectionChange::STATUS_RUNNING)
                ->when($claimToken, fn ($query) => $query->where('claim_token', $claimToken))
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                return;
            }

            $blocked = (int) $locked->attempt_count >= $this->maxAttempts();
            $locked->update([
                'publication_status' => $blocked
                    ? EmailLiveProjectionChange::STATUS_BLOCKED
                    : EmailLiveProjectionChange::STATUS_PENDING,
                'claim_token' => null,
                'attempt_count' => $blocked ? $this->maxAttempts() : $locked->attempt_count,
                'next_attempt_at' => $blocked ? null : $this->retryAt(),
                'error_code' => $blocked ? self::ERROR_ATTEMPTS : $errorCode,
            ]);
        });
    }

    private function continuePublications(): void
    {
        EmailLiveProjectionPublication::query()
            ->where('status', EmailLiveProjectionPublication::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->each(fn (int $publicationId) => $this->processPublicationPage($publicationId));
    }

    private function processPublicationPage(int $publicationId): void
    {
        $claim = null;

        try {
            $claim = $this->claimPublicationPage($publicationId);
            if (! $claim) {
                return;
            }

            $this->commitPublicationPage(
                $publicationId,
                $claim['token'],
                $claim['candidates'],
            );
        } catch (Throwable) {
            $this->safeLog('candidate_page_failed', $publicationId);
            $this->failPublicationPage($publicationId, $claim['token'] ?? null);
        }
    }

    /**
     * @return array{token: string, candidates: list<array{user_id: int}>}|null
     */
    private function claimPublicationPage(int $publicationId): ?array
    {
        return DB::transaction(function () use ($publicationId): ?array {
            $publication = EmailLiveProjectionPublication::query()
                ->lockForUpdate()
                ->find($publicationId);

            if (! $publication
                || $publication->status !== EmailLiveProjectionPublication::STATUS_PENDING
                || (int) $publication->attempt_count >= $this->maxAttempts()
                || $publication->next_attempt_at?->isFuture()) {
                return null;
            }

            $page = $this->candidatePage($publication);
            $token = $this->token();

            $publication->update([
                'status' => EmailLiveProjectionPublication::STATUS_RUNNING,
                'claim_token' => $token,
                'page_through_id' => $page['through_id'],
                'page_row_count' => count($page['candidates']),
                'attempt_count' => (int) $publication->attempt_count + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'error_code' => null,
            ]);

            return [
                'token' => $token,
                'candidates' => $page['candidates'],
            ];
        });
    }

    /** @return array{through_id: int, candidates: list<array{user_id: int}>} */
    private function candidatePage(EmailLiveProjectionPublication $publication): array
    {
        $cursor = (int) $publication->candidate_cursor_id;
        $through = $this->phaseThrough($publication);
        $limit = $this->pageSize();
        $rows = collect();

        if ($publication->phase === EmailLiveProjectionPublication::PHASE_OWNER) {
            if ($through === 1 && $cursor === 0) {
                $rows = collect([(object) ['id' => 1, 'user_id' => $publication->frozen_owner_user_id]]);
            }
        } elseif ($publication->phase === EmailLiveProjectionPublication::PHASE_GRANTS) {
            $rows = DB::table('email_account_user_grants')
                ->where('email_account_id', $publication->email_account_id)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $through)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'user_id']);
        } elseif ($publication->phase === EmailLiveProjectionPublication::PHASE_DELEGATIONS) {
            $rows = DB::table('email_mailbox_delegations')
                ->where('email_account_id', $publication->email_account_id)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $through)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'delegate_id as user_id']);
        } elseif ($publication->phase === EmailLiveProjectionPublication::PHASE_BREAK_GLASS) {
            $rows = DB::table('email_break_glass_accesses')
                ->where('email_account_id', $publication->email_account_id)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $through)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'actor_id as user_id']);
        } elseif ($publication->phase === EmailLiveProjectionPublication::PHASE_ACTIVE_USERS) {
            $rows = DB::table('user_management')
                ->where('id', '>', $cursor)
                ->where('id', '<=', $through)
                ->orderBy('id')
                ->limit($limit)
                ->get(['id', 'id as user_id']);
        }

        $pageThrough = $rows->isEmpty() ? $through : (int) $rows->last()->id;
        $candidates = $rows
            ->map(fn (object $row): array => ['user_id' => (int) $row->user_id])
            ->filter(fn (array $row): bool => $row['user_id'] > 0)
            ->values()
            ->all();

        return ['through_id' => $pageThrough, 'candidates' => $candidates];
    }

    /** @param list<array{user_id: int}> $candidates */
    private function commitPublicationPage(
        int $publicationId,
        string $claimToken,
        array $candidates,
    ): void {
        DB::transaction(function () use ($candidates, $claimToken, $publicationId): void {
            $publication = EmailLiveProjectionPublication::query()
                ->whereKey($publicationId)
                ->where('status', EmailLiveProjectionPublication::STATUS_RUNNING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();

            if (! $publication) {
                return;
            }

            foreach ($candidates as $candidate) {
                $access = DB::table('email_live_user_access_states')
                    ->where('user_id', $candidate['user_id'])
                    ->first();
                if (! $access) {
                    // A missing bootstrapped authority row cannot be treated
                    // as a terminal suppression because no frozen epoch can
                    // be attested. Retry finitely and expose blocked evidence.
                    throw new \LogicException('Email live recipient authority state is unavailable.');
                }

                EmailLiveProjectionDelivery::query()->firstOrCreate([
                    'source_change_id' => $publication->source_change_id,
                    'user_id' => $candidate['user_id'],
                ], [
                    'publication_id' => $publication->id,
                    'frozen_user_authorization_epoch' => $access->authorization_epoch,
                    'status' => EmailLiveProjectionDelivery::STATUS_PENDING,
                ]);
            }

            $phaseComplete = (int) $publication->page_through_id >= $this->phaseThrough($publication);
            $nextPhase = $phaseComplete ? $this->nextPhase($publication) : $publication->phase;
            $sealed = $nextPhase === EmailLiveProjectionPublication::PHASE_SEALED;
            $nextCursor = $phaseComplete ? 0 : (int) $publication->page_through_id;
            $deliveryThrough = $sealed
                ? (int) (EmailLiveProjectionDelivery::query()
                    ->where('publication_id', $publication->id)
                    ->max('id') ?? 0)
                : $publication->delivery_through_id;

            $publication->update([
                'phase' => $nextPhase,
                'candidate_cursor_id' => $nextCursor,
                'status' => $sealed
                    ? EmailLiveProjectionPublication::STATUS_SEALED
                    : EmailLiveProjectionPublication::STATUS_PENDING,
                'claim_token' => null,
                'page_through_id' => null,
                'page_row_count' => null,
                'page_count' => (int) $publication->page_count + 1,
                'next_attempt_at' => null,
                'completed_at' => $sealed ? now() : null,
                'error_code' => null,
                'delivery_summary_status' => $sealed ? 'pending' : 'waiting',
                'delivery_through_id' => $deliveryThrough,
            ]);
        });
    }

    private function phaseThrough(EmailLiveProjectionPublication $publication): int
    {
        return match ($publication->phase) {
            EmailLiveProjectionPublication::PHASE_OWNER => $publication->frozen_owner_user_id ? 1 : 0,
            EmailLiveProjectionPublication::PHASE_GRANTS => (int) $publication->grant_through_id,
            EmailLiveProjectionPublication::PHASE_DELEGATIONS => (int) $publication->delegation_through_id,
            EmailLiveProjectionPublication::PHASE_BREAK_GLASS => (int) $publication->break_glass_through_id,
            EmailLiveProjectionPublication::PHASE_ACTIVE_USERS => (int) $publication->active_user_through_id,
            default => (int) $publication->candidate_cursor_id,
        };
    }

    private function nextPhase(EmailLiveProjectionPublication $publication): string
    {
        return match ($publication->phase) {
            EmailLiveProjectionPublication::PHASE_OWNER => EmailLiveProjectionPublication::PHASE_GRANTS,
            EmailLiveProjectionPublication::PHASE_GRANTS => EmailLiveProjectionPublication::PHASE_DELEGATIONS,
            EmailLiveProjectionPublication::PHASE_DELEGATIONS => EmailLiveProjectionPublication::PHASE_BREAK_GLASS,
            default => EmailLiveProjectionPublication::PHASE_SEALED,
        };
    }

    private function failPublicationPage(int $publicationId, ?string $claimToken): void
    {
        if (! $claimToken) {
            return;
        }

        DB::transaction(function () use ($claimToken, $publicationId): void {
            $publication = EmailLiveProjectionPublication::query()
                ->whereKey($publicationId)
                ->where('status', EmailLiveProjectionPublication::STATUS_RUNNING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();
            if (! $publication) {
                return;
            }

            $blocked = (int) $publication->attempt_count >= $this->maxAttempts();
            $publication->update([
                'status' => $blocked
                    ? EmailLiveProjectionPublication::STATUS_BLOCKED
                    : EmailLiveProjectionPublication::STATUS_PENDING,
                'claim_token' => null,
                'page_through_id' => null,
                'page_row_count' => null,
                'next_attempt_at' => $blocked ? null : $this->retryAt(),
                'completed_at' => $blocked ? now() : null,
                'error_code' => $blocked ? self::ERROR_ATTEMPTS : self::ERROR_CANDIDATE_PAGE,
            ]);

            if ($blocked) {
                $this->blockSourceChange($publication);
            }
        });
    }

    private function continueDeliveries(): void
    {
        EmailLiveProjectionDelivery::query()
            ->where('status', EmailLiveProjectionDelivery::STATUS_PENDING)
            ->where(fn ($query) => $query
                ->whereNull('next_attempt_at')
                ->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(100)
            ->pluck('id')
            ->each(fn (int $deliveryId) => $this->processDelivery($deliveryId));
    }

    private function processDelivery(int $deliveryId): void
    {
        $delivery = $this->claimDelivery($deliveryId);
        if (! $delivery) {
            return;
        }

        try {
            $this->appendOrSuppressDelivery($delivery);
        } catch (Throwable) {
            $this->safeLog('delivery_append_failed', $deliveryId);
            $this->failDelivery($deliveryId, (string) $delivery->claim_token);
        }
    }

    private function claimDelivery(int $deliveryId): ?EmailLiveProjectionDelivery
    {
        return DB::transaction(function () use ($deliveryId): ?EmailLiveProjectionDelivery {
            $delivery = EmailLiveProjectionDelivery::query()->lockForUpdate()->find($deliveryId);
            if (! $delivery
                || $delivery->status !== EmailLiveProjectionDelivery::STATUS_PENDING
                || (int) $delivery->attempt_count >= $this->maxAttempts()
                || $delivery->next_attempt_at?->isFuture()) {
                return null;
            }

            $delivery->update([
                'status' => EmailLiveProjectionDelivery::STATUS_RUNNING,
                'claim_token' => $this->token(),
                'attempt_count' => (int) $delivery->attempt_count + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'error_code' => null,
            ]);

            return $delivery->fresh();
        });
    }

    private function appendOrSuppressDelivery(EmailLiveProjectionDelivery $claimed): void
    {
        $derivedChangeId = DB::transaction(function () use ($claimed): ?int {
            $delivery = EmailLiveProjectionDelivery::query()
                ->whereKey($claimed->id)
                ->where('status', EmailLiveProjectionDelivery::STATUS_RUNNING)
                ->where('claim_token', $claimed->claim_token)
                ->lockForUpdate()
                ->first();
            if (! $delivery) {
                return null;
            }

            $publication = EmailLiveProjectionPublication::query()
                ->lockForUpdate()
                ->find($delivery->publication_id);
            $sourceChange = EmailLiveProjectionChange::query()
                ->lockForUpdate()
                ->find($delivery->source_change_id);
            $user = User::query()->lockForUpdate()->find($delivery->user_id);

            if (! $publication || ! $sourceChange || ! $user || ! $this->currentlyAuthorized($user, $publication)) {
                $this->suppressDelivery($delivery, self::SUPPRESSED_UNAUTHORIZED);

                return null;
            }

            $access = DB::table('email_live_user_access_states')
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            $authority = $access ? $this->sourceAuthority($user, $publication) : null;
            if (! $access || ! $authority) {
                $this->suppressDelivery($delivery, self::SUPPRESSED_SOURCE_PATH);

                return null;
            }

            $globalAuthorizationGeneration = (int) DB::table('email_live_global_authority_states')
                ->where('id', 1)
                ->value('authorization_generation');
            $exactIdentifiers = (int) $access->authorization_epoch
                    === (int) $delivery->frozen_user_authorization_epoch
                && $access->recompute_status === 'sealed'
                && (int) $access->global_authorization_generation_seen === $globalAuthorizationGeneration;

            $stream = EmailLiveProjectionStream::query()->firstOrCreate([
                'stream_type' => EmailLiveProjectionStream::TYPE_USER,
                'user_id' => $user->id,
            ], [
                'current_version' => 0,
                'oldest_retained_version' => 1,
            ]);
            $stream = EmailLiveProjectionStream::query()->whereKey($stream->id)->lockForUpdate()->firstOrFail();
            $idempotencyKey = hash('sha256', "fanout:{$sourceChange->id}:{$user->id}");
            $derived = EmailLiveProjectionChange::query()
                ->where('stream_id', $stream->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if (! $derived) {
                $version = (int) $stream->current_version + 1;
                $stream->update([
                    'current_version' => $version,
                    'last_changed_at' => now(),
                ]);

                $types = $sourceChange->change_types_json;
                if (! $exactIdentifiers) {
                    $types[] = EmailLiveProjectionChange::TYPE_AUTHORIZATION;
                    $types = array_values(array_unique($types));
                    sort($types);
                }

                $conversationIds = $exactIdentifiers ? $sourceChange->conversation_ids_json : null;
                $placementIds = $exactIdentifiers ? $sourceChange->placement_ids_json : null;
                $derived = EmailLiveProjectionChange::query()->create([
                    'stream_id' => $stream->id,
                    'version' => $version,
                    'email_account_id' => $sourceChange->email_account_id,
                    'idempotency_key' => $idempotencyKey,
                    'change_types_json' => $types,
                    'conversation_ids_json' => $conversationIds,
                    'placement_ids_json' => $placementIds,
                    'conversation_id_count' => count($conversationIds ?? []),
                    'placement_id_count' => count($placementIds ?? []),
                    'truncated' => ! $exactIdentifiers || $sourceChange->truncated,
                    'publication_status' => EmailLiveProjectionChange::STATUS_PENDING,
                    'available_at' => now(),
                ]);
            }

            $delivery->update([
                'authority_kind' => $authority['kind'],
                'authority_id' => $authority['id'],
                'authority_enable_generation' => $authority['generation'],
                'content_authority_path_id' => $authority['content_path_id'],
                'frozen_content_authority_generation' => $authority['content_generation'],
                'derived_change_id' => $derived->id,
                'derived_stream_id' => $stream->id,
                'status' => EmailLiveProjectionDelivery::STATUS_APPENDED,
                'claim_token' => null,
                'next_attempt_at' => null,
                'completed_at' => now(),
                'error_code' => null,
            ]);

            return (int) $derived->id;
        });

        if ($derivedChangeId) {
            EmailLivePublisher::dispatch($derivedChangeId);
        }
    }

    private function currentlyAuthorized(
        User $user,
        EmailLiveProjectionPublication $publication,
    ): bool {
        if (! $user->isActive() || $user->isSystemActor() || ! $user->can('email.inbox_view')) {
            return false;
        }

        if ($publication->source_stream_type === EmailLiveProjectionStream::TYPE_GLOBAL) {
            return true;
        }

        $account = EmailAccount::query()->find($publication->email_account_id);

        return $account !== null
            && app(ResolveMailboxAccessDecision::class)
                ->resolve($user, $account, ResolveMailboxAccessDecision::CONTENT_VIEW)
                ->allowed;
    }

    /**
     * @return array{kind: string, id: int, generation: int, content_path_id: int, content_generation: int}|null
     */
    private function sourceAuthority(
        User $user,
        EmailLiveProjectionPublication $publication,
    ): ?array {
        $contentPath = DB::table('email_live_user_content_authority_paths as path')
            ->join('permissions as permission', 'permission.id', '=', 'path.permission_id')
            ->where('path.user_id', $user->id)
            ->where('path.enabled', true)
            ->where('permission.name', 'email.inbox_view')
            ->where('path.enable_generation', '<=', $publication->global_content_ability_generation)
            ->where('path.enabled_at', '<=', $publication->source_at)
            ->orderBy('path.id')
            ->select(['path.id', 'path.enable_generation'])
            ->lockForUpdate()
            ->first();
        if (! $contentPath) {
            return null;
        }

        $authority = $publication->source_stream_type === EmailLiveProjectionStream::TYPE_GLOBAL
            ? $this->globalSourceAuthority($user, $publication)
            : $this->accountSourceAuthority($user, $publication);
        if (! $authority) {
            return null;
        }

        return $authority + [
            'content_path_id' => (int) $contentPath->id,
            'content_generation' => (int) $contentPath->enable_generation,
        ];
    }

    /** @return array{kind: string, id: int, generation: int}|null */
    private function globalSourceAuthority(
        User $user,
        EmailLiveProjectionPublication $publication,
    ): ?array {
        $sourceUser = DB::table('user_management')
            ->where('id', $user->id)
            ->where('id', '<=', $publication->active_user_through_id)
            ->where('email_live_enable_generation', '<=', $publication->global_active_user_generation)
            ->where('created_at', '<=', $publication->source_at)
            ->first(['id', 'email_live_enable_generation']);

        return $sourceUser ? [
            'kind' => EmailLiveProjectionDelivery::AUTHORITY_ACTIVE_USER,
            'id' => (int) $sourceUser->id,
            'generation' => (int) $sourceUser->email_live_enable_generation,
        ] : null;
    }

    /** @return array{kind: string, id: int, generation: int}|null */
    private function accountSourceAuthority(
        User $user,
        EmailLiveProjectionPublication $publication,
    ): ?array {
        $accountState = DB::table('email_live_account_authority_states')
            ->where('email_account_id', $publication->email_account_id)
            ->where('audience_generation', $publication->account_audience_generation)
            ->lockForUpdate()
            ->first();
        if (! $accountState) {
            return null;
        }

        if ((int) $publication->frozen_owner_user_id === (int) $user->id
            && (int) $accountState->owner_user_id === (int) $user->id
            && (int) $accountState->owner_enable_generation <= (int) $publication->account_audience_generation) {
            return [
                'kind' => EmailLiveProjectionDelivery::AUTHORITY_OWNER,
                'id' => (int) $user->id,
                'generation' => (int) $accountState->owner_enable_generation,
            ];
        }

        $grant = DB::table('email_account_user_grants')
            ->where('email_account_id', $publication->email_account_id)
            ->where('user_id', $user->id)
            ->where('id', '<=', $publication->grant_through_id)
            ->where('can_view', true)
            ->where('email_live_enable_generation', '<=', $publication->account_audience_generation)
            ->where('created_at', '<=', $publication->source_at)
            ->where('updated_at', '<=', $publication->source_at)
            ->orderBy('id')
            ->first(['id', 'email_live_enable_generation']);
        if ($grant) {
            return [
                'kind' => EmailLiveProjectionDelivery::AUTHORITY_GRANT,
                'id' => (int) $grant->id,
                'generation' => (int) $grant->email_live_enable_generation,
            ];
        }

        $delegation = DB::table('email_mailbox_delegations')
            ->where('email_account_id', $publication->email_account_id)
            ->where('delegate_id', $user->id)
            ->where('id', '<=', $publication->delegation_through_id)
            ->where('can_view', true)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $publication->source_at)
            ->where('expires_at', '>', $publication->source_at)
            ->where('email_live_enable_generation', '<=', $publication->account_audience_generation)
            ->where('created_at', '<=', $publication->source_at)
            ->where('updated_at', '<=', $publication->source_at)
            ->orderBy('id')
            ->first(['id', 'email_live_enable_generation']);
        if ($delegation) {
            return [
                'kind' => EmailLiveProjectionDelivery::AUTHORITY_DELEGATION,
                'id' => (int) $delegation->id,
                'generation' => (int) $delegation->email_live_enable_generation,
            ];
        }

        $breakGlass = DB::table('email_break_glass_accesses')
            ->where('email_account_id', $publication->email_account_id)
            ->where('actor_id', $user->id)
            ->where('id', '<=', $publication->break_glass_through_id)
            ->where('can_view_content', true)
            ->whereNull('revoked_at')
            ->where('starts_at', '<=', $publication->source_at)
            ->where('expires_at', '>', $publication->source_at)
            ->where('email_live_enable_generation', '<=', $publication->account_audience_generation)
            ->where('created_at', '<=', $publication->source_at)
            ->where('updated_at', '<=', $publication->source_at)
            ->orderBy('id')
            ->first(['id', 'email_live_enable_generation']);

        return $breakGlass ? [
            'kind' => EmailLiveProjectionDelivery::AUTHORITY_BREAK_GLASS,
            'id' => (int) $breakGlass->id,
            'generation' => (int) $breakGlass->email_live_enable_generation,
        ] : null;
    }

    private function suppressDelivery(EmailLiveProjectionDelivery $delivery, string $code): void
    {
        $delivery->update([
            'status' => EmailLiveProjectionDelivery::STATUS_SUPPRESSED,
            'claim_token' => null,
            'next_attempt_at' => null,
            'completed_at' => now(),
            'error_code' => $code,
        ]);
    }

    private function failDelivery(int $deliveryId, string $claimToken): void
    {
        DB::transaction(function () use ($claimToken, $deliveryId): void {
            $delivery = EmailLiveProjectionDelivery::query()
                ->whereKey($deliveryId)
                ->where('status', EmailLiveProjectionDelivery::STATUS_RUNNING)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();
            if (! $delivery) {
                return;
            }

            $blocked = (int) $delivery->attempt_count >= $this->maxAttempts();
            $delivery->update([
                'status' => $blocked
                    ? EmailLiveProjectionDelivery::STATUS_BLOCKED
                    : EmailLiveProjectionDelivery::STATUS_PENDING,
                'claim_token' => null,
                'next_attempt_at' => $blocked ? null : $this->retryAt(),
                'completed_at' => $blocked ? now() : null,
                'error_code' => $blocked ? self::ERROR_ATTEMPTS : self::ERROR_APPEND,
            ]);

            if ($blocked) {
                $publication = EmailLiveProjectionPublication::query()
                    ->lockForUpdate()
                    ->find($delivery->publication_id);
                if ($publication) {
                    $this->blockSourceChange($publication);
                }
            }
        });
    }

    private function continueDeliverySummaries(): void
    {
        EmailLiveProjectionPublication::query()
            ->where('delivery_summary_status', 'pending')
            ->where(fn ($query) => $query
                ->whereNull('delivery_next_attempt_at')
                ->orWhere('delivery_next_attempt_at', '<=', now()))
            ->orderBy('id')
            ->limit(20)
            ->pluck('id')
            ->each(fn (int $publicationId) => $this->summarizeDeliveryPage($publicationId));
    }

    private function summarizeDeliveryPage(int $publicationId): void
    {
        DB::transaction(function () use ($publicationId): void {
            $publication = EmailLiveProjectionPublication::query()
                ->whereKey($publicationId)
                ->where('status', EmailLiveProjectionPublication::STATUS_SEALED)
                ->where('delivery_summary_status', 'pending')
                ->lockForUpdate()
                ->first();
            if (! $publication) {
                return;
            }

            $deliveries = EmailLiveProjectionDelivery::query()
                ->where('publication_id', $publication->id)
                ->where('id', '>', $publication->delivery_cursor_id)
                ->where('id', '<=', $publication->delivery_through_id)
                ->orderBy('id')
                ->limit($this->pageSize())
                ->get();

            if ($deliveries->contains(fn (EmailLiveProjectionDelivery $delivery): bool => $delivery->status === EmailLiveProjectionDelivery::STATUS_BLOCKED)) {
                $publication->update([
                    'delivery_summary_status' => 'blocked',
                    'delivery_attempt_count' => $this->maxAttempts(),
                    'delivery_error_code' => self::ERROR_ATTEMPTS,
                ]);
                $this->blockSourceChange($publication);

                return;
            }

            if ($deliveries->contains(fn (EmailLiveProjectionDelivery $delivery): bool => ! in_array($delivery->status, [
                EmailLiveProjectionDelivery::STATUS_APPENDED,
                EmailLiveProjectionDelivery::STATUS_SUPPRESSED,
            ], true))) {
                return;
            }

            $pageThrough = $deliveries->isEmpty()
                ? (int) $publication->delivery_through_id
                : (int) $deliveries->last()->id;
            $pageCount = $deliveries->count();
            $appended = $deliveries->where('status', EmailLiveProjectionDelivery::STATUS_APPENDED)->count();
            $suppressed = $deliveries->where('status', EmailLiveProjectionDelivery::STATUS_SUPPRESSED)->count();
            $sealed = $pageThrough >= (int) $publication->delivery_through_id;

            $publication->update([
                'delivery_summary_status' => 'running',
                'delivery_claim_token' => $this->token(),
                'delivery_page_through_id' => $pageThrough,
                'delivery_page_row_count' => $pageCount,
                'delivery_attempt_count' => (int) $publication->delivery_attempt_count + 1,
                'delivery_last_attempt_at' => now(),
                'delivery_error_code' => null,
            ]);
            $publication->update([
                'delivery_summary_status' => $sealed ? 'sealed' : 'pending',
                'delivery_cursor_id' => $pageThrough,
                'delivery_count' => (int) $publication->delivery_count + $pageCount,
                'delivery_appended_count' => (int) $publication->delivery_appended_count + $appended,
                'delivery_suppressed_count' => (int) $publication->delivery_suppressed_count + $suppressed,
                'delivery_claim_token' => null,
                'delivery_page_through_id' => null,
                'delivery_page_row_count' => null,
                'delivery_page_count' => (int) $publication->delivery_page_count + 1,
                'delivery_next_attempt_at' => null,
                'delivery_sealed_at' => $sealed ? now() : null,
                'delivery_error_code' => null,
            ]);

            if ($sealed) {
                $this->sealSourceChange($publication->fresh());
            }
        });
    }

    private function sealSourceChange(EmailLiveProjectionPublication $publication): void
    {
        $change = EmailLiveProjectionChange::query()
            ->whereKey($publication->source_change_id)
            ->where('publication_status', EmailLiveProjectionChange::STATUS_RUNNING)
            ->lockForUpdate()
            ->first();
        if (! $change) {
            return;
        }

        $change->update([
            'publication_status' => EmailLiveProjectionChange::STATUS_SEALED,
            'claim_token' => null,
            'sealed_at' => now(),
            'retention_ready_at' => now(),
            'compact_delivery_count' => $publication->delivery_count,
            'compact_appended_count' => $publication->delivery_appended_count,
            'compact_suppressed_count' => $publication->delivery_suppressed_count,
            'next_attempt_at' => null,
            'error_code' => null,
        ]);
    }

    private function blockSourceChange(EmailLiveProjectionPublication $publication): void
    {
        $change = EmailLiveProjectionChange::query()
            ->whereKey($publication->source_change_id)
            ->where('publication_status', EmailLiveProjectionChange::STATUS_RUNNING)
            ->lockForUpdate()
            ->first();
        if (! $change) {
            return;
        }

        $change->update([
            'publication_status' => EmailLiveProjectionChange::STATUS_BLOCKED,
            'claim_token' => null,
            'attempt_count' => $this->maxAttempts(),
            'next_attempt_at' => null,
            'error_code' => self::ERROR_ATTEMPTS,
        ]);
    }

    private function recoverAbandonedClaims(): void
    {
        $cutoff = now()->subSeconds(max(1, (int) config('email_live.abandoned_claim_seconds', 90)));

        EmailLiveProjectionChange::query()
            ->where('publication_status', EmailLiveProjectionChange::STATUS_RUNNING)
            ->where('last_attempt_at', '<=', $cutoff)
            ->whereDoesntHave('publication')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(fn (EmailLiveProjectionChange $change) => $this->failChange(
                $change,
                self::ERROR_TRANSPORT,
                (string) $change->claim_token,
            ));

        EmailLiveProjectionPublication::query()
            ->where('status', EmailLiveProjectionPublication::STATUS_RUNNING)
            ->where('last_attempt_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->each(fn (EmailLiveProjectionPublication $publication) => $this->failPublicationPage(
                (int) $publication->id,
                (string) $publication->claim_token,
            ));

        EmailLiveProjectionDelivery::query()
            ->where('status', EmailLiveProjectionDelivery::STATUS_RUNNING)
            ->where('last_attempt_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(100)
            ->get()
            ->each(fn (EmailLiveProjectionDelivery $delivery) => $this->failDelivery(
                (int) $delivery->id,
                (string) $delivery->claim_token,
            ));
    }

    private function broadcast(int $userId, EmailLiveProjectionChange $change): void
    {
        broadcast(new EmailProjectionInvalidated($userId, [
            'schema' => 1,
            'scope' => 'user',
            'from_version' => (string) max(0, (int) $change->version - 1),
            'to_version' => (string) $change->version,
            'change_types' => $change->change_types_json,
            'conversation_ids' => $change->conversation_ids_json ?? [],
            'placement_ids' => $change->placement_ids_json ?? [],
            'truncated' => (bool) $change->truncated,
        ]));
    }

    private function safeLog(string $code, int $evidenceId): void
    {
        Log::warning('Email live publisher operation failed.', [
            'code' => $code,
            'evidence_id' => $evidenceId,
        ]);
    }
}
