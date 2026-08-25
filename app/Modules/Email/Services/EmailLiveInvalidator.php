<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

class EmailLiveInvalidator
{
    /**
     * Record a batch of invalidation hints inside the current transaction.
     *
     * @param array{
     *   global?: array<string>,
     *   account?: array<int, array<string>>,
     *   user?: array<int, array<string>>,
     *   conversations?: array<int>,
     *   placements?: array<int>,
     *   idempotency_key: string
     * } $batch
     */
    public function record(array $batch): void
    {
        // The live pipeline is fail-closed until its runtime activation gates pass.
        // Mail mutations must continue to work while the optional hint transport is off.
        if (! config('email_live.enabled', false)) {
            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('Email live invalidation requires an active transaction.');
        }

        $this->validateBatch($batch);
        $batch['idempotency_key'] = $this->batchIdempotencyKey($batch['idempotency_key'] ?? null);
        $sortedStreams = $this->sortAndLockStreams($batch);

        foreach ($sortedStreams as $streamData) {
            $change = $this->appendChange($streamData['stream'], $streamData['types'], $batch);

            if (! $change) {
                continue;
            }

            if ($streamData['stream']->stream_type !== EmailLiveProjectionStream::TYPE_USER) {
                $this->freezePublication($change, $streamData['stream']);
            }

            $changeId = (int) $change->id;
            DB::afterCommit(static function () use ($changeId): void {
                \App\Modules\Email\Jobs\EmailLivePublisher::dispatch($changeId);
            });
        }
    }

    private function validateBatch(array $batch): void
    {
        foreach (['global', 'account', 'user'] as $scope) {
            if (isset($batch[$scope]) && ! is_array($batch[$scope])) {
                throw new InvalidArgumentException('Email live invalidation scopes must be arrays.');
            }
        }

        foreach (['account', 'user'] as $scope) {
            foreach (($batch[$scope] ?? []) as $identifier => $types) {
                if ((! is_int($identifier) && ! ctype_digit((string) $identifier))
                    || (int) $identifier < 1
                    || ! is_array($types)) {
                    throw new InvalidArgumentException('Email live invalidation stream identifiers must be positive integers.');
                }
            }
        }

        $typeSets = array_merge(
            isset($batch['global']) ? [$batch['global']] : [],
            array_values($batch['account'] ?? []),
            array_values($batch['user'] ?? []),
        );
        foreach ($typeSets as $types) {
            $types = array_values(array_unique($types));
            if ($types === [] || array_diff($types, EmailLiveProjectionChange::ALLOWED_TYPES) !== []) {
                throw new InvalidArgumentException('Email live invalidation contains an unsupported change type.');
            }
        }

        $this->normalizeIdentifiers($batch['conversations'] ?? []);
        $this->normalizeIdentifiers($batch['placements'] ?? []);
    }

    private function sortAndLockStreams(array $batch): array
    {
        $locks = [];

        // 1. Global stream
        if (! empty($batch['global'])) {
            $locks[] = [
                'key' => 'global:1',
                'type' => EmailLiveProjectionStream::TYPE_GLOBAL,
                'id' => 1,
                'types' => $batch['global'],
            ];
        }

        // 2. Account streams
        if (! empty($batch['account'])) {
            $accountIds = array_keys($batch['account']);
            sort($accountIds);
            foreach ($accountIds as $accountId) {
                $locks[] = [
                    'key' => "account:{$accountId}",
                    'type' => EmailLiveProjectionStream::TYPE_ACCOUNT,
                    'id' => $accountId,
                    'types' => $batch['account'][$accountId],
                ];
            }
        }

        // 3. User streams
        if (! empty($batch['user'])) {
            $userIds = array_keys($batch['user']);
            sort($userIds);
            foreach ($userIds as $userId) {
                $locks[] = [
                    'key' => "user:{$userId}",
                    'type' => EmailLiveProjectionStream::TYPE_USER,
                    'id' => $userId,
                    'types' => $batch['user'][$userId],
                ];
            }
        }

        $results = [];
        foreach ($locks as $lock) {
            $stream = EmailLiveProjectionStream::query()
                ->where('stream_type', $lock['type'])
                ->when($lock['type'] === EmailLiveProjectionStream::TYPE_ACCOUNT, fn ($q) => $q->where('email_account_id', $lock['id']))
                ->when($lock['type'] === EmailLiveProjectionStream::TYPE_USER, fn ($q) => $q->where('user_id', $lock['id']))
                ->when($lock['type'] === EmailLiveProjectionStream::TYPE_GLOBAL, fn ($q) => $q->where('global_slot', 1))
                ->lockForUpdate()
                ->first();

            if (! $stream) {
                // Initialize stream if it doesn't exist
                $stream = EmailLiveProjectionStream::create([
                    'stream_type' => $lock['type'],
                    'email_account_id' => $lock['type'] === EmailLiveProjectionStream::TYPE_ACCOUNT ? $lock['id'] : null,
                    'user_id' => $lock['type'] === EmailLiveProjectionStream::TYPE_USER ? $lock['id'] : null,
                    'global_slot' => $lock['type'] === EmailLiveProjectionStream::TYPE_GLOBAL ? 1 : null,
                    'current_version' => 0,
                    'oldest_retained_version' => 1,
                ]);

                // Lock it again to be sure
                $stream = EmailLiveProjectionStream::query()
                    ->where('id', $stream->id)
                    ->lockForUpdate()
                    ->first();
            }

            $results[] = [
                'stream' => $stream,
                'types' => $lock['types'],
            ];
        }

        return $results;
    }

    private function appendChange(
        EmailLiveProjectionStream $stream,
        array $types,
        array $batch,
    ): ?EmailLiveProjectionChange {
        $types = array_values(array_unique($types));
        sort($types);

        if ($types === [] || array_diff($types, EmailLiveProjectionChange::ALLOWED_TYPES) !== []) {
            throw new InvalidArgumentException('Email live invalidation contains an unsupported change type.');
        }

        $idempotencyKey = hash('sha256', implode(':', [
            $batch['idempotency_key'],
            $stream->stream_type,
            (string) ($stream->email_account_id ?? $stream->user_id ?? $stream->global_slot),
        ]));

        // A caller-supplied operation key makes a retry a true no-op for this stream.
        if (EmailLiveProjectionChange::query()
            ->where('stream_id', $stream->id)
            ->where('idempotency_key', $idempotencyKey)
            ->exists()) {
            return null;
        }

        $version = $stream->current_version + 1;

        $stream->update([
            'current_version' => $version,
            'last_changed_at' => now(),
        ]);

        $conversationIds = $this->normalizeIdentifiers($batch['conversations'] ?? []);
        $placementIds = $this->normalizeIdentifiers($batch['placements'] ?? []);

        // Truncate IDs if they exceed limits from config
        $idLimit = min(50, max(1, (int) config('email_live.identifier_limit', 50)));
        $truncated = false;

        if (count($conversationIds) > $idLimit) {
            $conversationIds = array_slice($conversationIds, 0, $idLimit);
            $truncated = true;
        }

        if (count($placementIds) > $idLimit) {
            $placementIds = array_slice($placementIds, 0, $idLimit);
            $truncated = true;
        }

        return EmailLiveProjectionChange::create([
            'stream_id' => $stream->id,
            'version' => $version,
            'email_account_id' => $stream->email_account_id,
            'idempotency_key' => $idempotencyKey,
            'change_types_json' => $types,
            'conversation_ids_json' => $conversationIds === [] ? null : $conversationIds,
            'placement_ids_json' => $placementIds === [] ? null : $placementIds,
            'conversation_id_count' => count($conversationIds),
            'placement_id_count' => count($placementIds),
            'truncated' => $truncated,
            'publication_status' => EmailLiveProjectionChange::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    private function batchIdempotencyKey(mixed $key): string
    {
        if (! is_string($key) || trim($key) === '') {
            throw new InvalidArgumentException(
                'Email live invalidation requires a stable, non-empty idempotency key.',
            );
        }

        return hash('sha256', $key);
    }

    /** Freeze account/global fanout evidence in the source append transaction. */
    private function freezePublication(
        EmailLiveProjectionChange $change,
        EmailLiveProjectionStream $stream,
    ): void {
        $global = DB::table('email_live_global_authority_states')
            ->where('id', 1)
            ->lockForUpdate()
            ->first();

        if (! $global) {
            throw new LogicException('Email live global authority state is unavailable.');
        }

        $attributes = [
            'source_change_id' => $change->id,
            'source_stream_id' => $stream->id,
            'source_stream_type' => $stream->stream_type,
            'email_account_id' => $stream->email_account_id,
            'source_at' => $change->created_at,
            'frozen_owner_user_id' => null,
            'account_audience_generation' => null,
            'global_active_user_generation' => null,
            'global_content_audience_generation' => $global->content_audience_generation,
            'global_content_ability_generation' => $global->content_ability_generation,
            'grant_through_id' => 0,
            'delegation_through_id' => 0,
            'break_glass_through_id' => 0,
            'active_user_through_id' => 0,
            'phase' => EmailLiveProjectionPublication::PHASE_ACTIVE_USERS,
            'candidate_cursor_id' => 0,
            'status' => EmailLiveProjectionPublication::STATUS_PENDING,
            'delivery_summary_status' => 'waiting',
        ];

        if ($stream->stream_type === EmailLiveProjectionStream::TYPE_ACCOUNT) {
            $account = DB::table('email_live_account_authority_states')
                ->where('email_account_id', $stream->email_account_id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw new LogicException('Email live account authority state is unavailable.');
            }

            $attributes = array_merge($attributes, [
                'frozen_owner_user_id' => $account->owner_user_id,
                'account_audience_generation' => $account->audience_generation,
                'grant_through_id' => DB::table('email_account_user_grants')
                    ->where('email_account_id', $stream->email_account_id)
                    ->max('id') ?? 0,
                'delegation_through_id' => DB::table('email_mailbox_delegations')
                    ->where('email_account_id', $stream->email_account_id)
                    ->max('id') ?? 0,
                'break_glass_through_id' => DB::table('email_break_glass_accesses')
                    ->where('email_account_id', $stream->email_account_id)
                    ->max('id') ?? 0,
                'phase' => EmailLiveProjectionPublication::PHASE_OWNER,
            ]);
        } else {
            $attributes['global_active_user_generation'] = $global->active_user_generation;
            $attributes['active_user_through_id'] = DB::table('user_management')->max('id') ?? 0;
        }

        EmailLiveProjectionPublication::query()->create($attributes);
    }

    /** @param array<mixed> $identifiers */
    private function normalizeIdentifiers(array $identifiers): array
    {
        foreach ($identifiers as $identifier) {
            if (! is_int($identifier) || $identifier < 1) {
                throw new InvalidArgumentException('Email live invalidation identifiers must be positive integers.');
            }
        }

        $identifiers = array_values(array_unique($identifiers));
        sort($identifiers);

        return $identifiers;
    }
}
