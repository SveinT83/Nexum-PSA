<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Events\EmailProjectionInvalidated;
use App\Modules\Email\Models\EmailLiveProjectionChange;
use App\Modules\Email\Models\EmailLiveProjectionDelivery;
use App\Modules\Email\Models\EmailLiveProjectionPublication;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailLivePublisherService
{
    /**
     * Publish pending changes that need fanout or direct broadcast.
     */
    public function publishPending(): void
    {
        if (! config('email_live.enabled', false)) {
            return;
        }

        $changes = EmailLiveProjectionChange::query()
            ->where('publication_status', EmailLiveProjectionChange::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', now());
            })
            ->limit(50)
            ->get();

        foreach ($changes as $change) {
            try {
                $this->publish($change);
            } catch (Exception $e) {
                Log::error("Failed to publish email live change {$change->id}: ".$e->getMessage());
                $this->markAsFailed($change, $e);
            }
        }

        // Also continue publications that are in progress
        $this->continuePublications();
        $this->continueDeliveries();
    }

    public function publish(EmailLiveProjectionChange $change): void
    {
        if (! config('email_live.enabled', false)) {
            return;
        }

        $stream = $change->stream;

        if ($stream->stream_type === EmailLiveProjectionStream::TYPE_USER) {
            $this->publishDirect($change, $stream);

            return;
        }

        $this->startPublication($change, $stream);
    }

    private function publishDirect(EmailLiveProjectionChange $change, EmailLiveProjectionStream $stream): void
    {
        DB::transaction(function () use ($change, $stream) {
            $change->update([
                'publication_status' => EmailLiveProjectionChange::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            // Direct publish just broadcasts to the user
            $this->broadcast($stream->user_id, $change);

            $change->update([
                'publication_status' => EmailLiveProjectionChange::STATUS_SEALED,
                'sealed_at' => now(),
            ]);

            // Update stream acknowledged version if it's a direct user stream change
            $stream->update([
                'acknowledged_version' => $change->version,
                'acknowledged_at' => now(),
            ]);
        });
    }

    private function startPublication(EmailLiveProjectionChange $change, EmailLiveProjectionStream $stream): void
    {
        DB::transaction(function () use ($change, $stream) {
            $change->update([
                'publication_status' => EmailLiveProjectionChange::STATUS_RUNNING,
                'attempt_count' => $change->attempt_count + 1,
            ]);

            // Create publication
            $publication = EmailLiveProjectionPublication::create([
                'source_change_id' => $change->id,
                'source_stream_id' => $stream->id,
                'source_stream_type' => $stream->stream_type,
                'email_account_id' => $change->email_account_id,
                'source_at' => $change->created_at,
                'status' => EmailLiveProjectionPublication::STATUS_PENDING,
                'phase' => $stream->stream_type === EmailLiveProjectionStream::TYPE_ACCOUNT
                    ? EmailLiveProjectionPublication::PHASE_OWNER
                    : EmailLiveProjectionPublication::PHASE_ACTIVE_USERS,
                'candidate_cursor_id' => 0,
                'delivery_summary_status' => 'waiting',
            ]);

            // Capture generations for fanout consistency (baselined in migration 130000)
            $this->captureGenerations($publication);
        });
    }

    private function captureGenerations(EmailLiveProjectionPublication $publication): void
    {
        $global = DB::table('email_live_global_authority_states')->where('id', 1)->first();

        $data = [
            'global_active_user_generation' => $global->active_user_generation,
            'global_content_audience_generation' => $global->content_audience_generation,
            'global_content_ability_generation' => $global->content_ability_generation,
        ];

        if ($publication->source_stream_type === EmailLiveProjectionStream::TYPE_ACCOUNT) {
            $account = DB::table('email_live_account_authority_states')
                ->where('email_account_id', $publication->email_account_id)
                ->first();

            $data['frozen_owner_user_id'] = $account->owner_user_id;
            $data['account_audience_generation'] = $account->audience_generation;

            // Through IDs for cursors
            $data['grant_through_id'] = DB::table('email_account_user_grants')
                ->where('email_account_id', $publication->email_account_id)
                ->max('id') ?? 0;
            $data['delegation_through_id'] = DB::table('email_mailbox_delegations')
                ->where('email_account_id', $publication->email_account_id)
                ->max('id') ?? 0;
            $data['break_glass_through_id'] = DB::table('email_break_glass_accesses')
                ->where('email_account_id', $publication->email_account_id)
                ->max('id') ?? 0;
        } else {
            $data['active_user_through_id'] = DB::table('user_management')->max('id') ?? 0;
        }

        $publication->update($data);
    }

    private function continuePublications(): void
    {
        $publications = EmailLiveProjectionPublication::query()
            ->where('status', EmailLiveProjectionPublication::STATUS_PENDING)
            ->limit(20)
            ->get();

        foreach ($publications as $publication) {
            $this->processPublicationPhases($publication);
        }
    }

    private function processPublicationPhases(EmailLiveProjectionPublication $publication): void
    {
        $token = Str::random(64);

        $updated = DB::table('email_live_projection_publications')
            ->where('id', $publication->id)
            ->where('status', EmailLiveProjectionPublication::STATUS_PENDING)
            ->update([
                'status' => EmailLiveProjectionPublication::STATUS_RUNNING,
                'claim_token' => $token,
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
            ]);

        if (! $updated) {
            return;
        }

        $publication->refresh();

        try {
            while ($publication->phase !== EmailLiveProjectionPublication::PHASE_SEALED) {
                $count = $this->runPublicationPhase($publication);

                if ($count >= 100) { // Page limit
                    break;
                }

                $this->advancePublicationPhase($publication);
            }

            if ($publication->phase === EmailLiveProjectionPublication::PHASE_SEALED) {
                $publication->update([
                    'status' => EmailLiveProjectionPublication::STATUS_SEALED,
                    'delivery_summary_status' => 'pending', // Trigger delivery fanout
                    'completed_at' => now(),
                ]);
            } else {
                $publication->update(['status' => EmailLiveProjectionPublication::STATUS_PENDING]);
            }
        } catch (Exception $e) {
            Log::error('Publication phase failed: '.$e->getMessage());
            $publication->update([
                'status' => EmailLiveProjectionPublication::STATUS_BLOCKED,
                'error_code' => 'phase_failed',
            ]);
        }
    }

    private function runPublicationPhase(EmailLiveProjectionPublication $publication): int
    {
        $batchSize = 100;
        $users = [];

        switch ($publication->phase) {
            case EmailLiveProjectionPublication::PHASE_OWNER:
                if ($publication->frozen_owner_user_id && $publication->candidate_cursor_id === 0) {
                    $users[] = [
                        'user_id' => $publication->frozen_owner_user_id,
                        'kind' => EmailLiveProjectionDelivery::AUTHORITY_OWNER,
                        'id' => $publication->frozen_owner_user_id,
                        'gen' => $publication->account_audience_generation,
                    ];
                }
                $publication->update(['candidate_cursor_id' => 1]);
                break;

            case EmailLiveProjectionPublication::PHASE_GRANTS:
                $grants = DB::table('email_account_user_grants')
                    ->where('email_account_id', $publication->email_account_id)
                    ->where('id', '>', $publication->candidate_cursor_id)
                    ->where('id', '<=', $publication->grant_through_id)
                    ->orderBy('id')
                    ->limit($batchSize)
                    ->get();

                foreach ($grants as $grant) {
                    $users[] = [
                        'user_id' => $grant->user_id,
                        'kind' => EmailLiveProjectionDelivery::AUTHORITY_GRANT,
                        'id' => $grant->id,
                        'gen' => $publication->account_audience_generation,
                    ];
                    $publication->candidate_cursor_id = $grant->id;
                }
                $publication->update(['candidate_cursor_id' => $publication->candidate_cursor_id]);
                break;

            case EmailLiveProjectionPublication::PHASE_DELEGATIONS:
                // Similar for delegations...
                break;

            case EmailLiveProjectionPublication::PHASE_ACTIVE_USERS:
                // For global streams...
                break;
        }

        foreach ($users as $u) {
            EmailLiveProjectionDelivery::create([
                'publication_id' => $publication->id,
                'source_change_id' => $publication->source_change_id,
                'user_id' => $u['user_id'],
                'authority_kind' => $u['kind'],
                'authority_id' => $u['id'],
                'authority_enable_generation' => $u['gen'],
                'status' => EmailLiveProjectionDelivery::STATUS_PENDING,
            ]);
        }

        return count($users);
    }

    private function advancePublicationPhase(EmailLiveProjectionPublication $publication): void
    {
        $phases = [
            EmailLiveProjectionPublication::PHASE_OWNER => EmailLiveProjectionPublication::PHASE_GRANTS,
            EmailLiveProjectionPublication::PHASE_GRANTS => EmailLiveProjectionPublication::PHASE_DELEGATIONS,
            EmailLiveProjectionPublication::PHASE_DELEGATIONS => EmailLiveProjectionPublication::PHASE_BREAK_GLASS,
            EmailLiveProjectionPublication::PHASE_BREAK_GLASS => EmailLiveProjectionPublication::PHASE_SEALED,
            EmailLiveProjectionPublication::PHASE_ACTIVE_USERS => EmailLiveProjectionPublication::PHASE_SEALED,
        ];

        $publication->update([
            'phase' => $phases[$publication->phase] ?? EmailLiveProjectionPublication::PHASE_SEALED,
            'candidate_cursor_id' => 0,
        ]);
    }

    private function continueDeliveries(): void
    {
        $deliveries = EmailLiveProjectionDelivery::query()
            ->where('status', EmailLiveProjectionDelivery::STATUS_PENDING)
            ->limit(100)
            ->get();

        foreach ($deliveries as $delivery) {
            $this->processDelivery($delivery);
        }
    }

    private function processDelivery(EmailLiveProjectionDelivery $delivery): void
    {
        DB::transaction(function () use ($delivery) {
            $delivery->update(['status' => EmailLiveProjectionDelivery::STATUS_RUNNING]);

            $userStream = EmailLiveProjectionStream::firstOrCreate([
                'stream_type' => EmailLiveProjectionStream::TYPE_USER,
                'user_id' => $delivery->user_id,
            ], [
                'current_version' => 0,
                'oldest_retained_version' => 1,
            ]);

            // Lock stream
            $userStream = EmailLiveProjectionStream::where('id', $userStream->id)->lockForUpdate()->first();

            $sourceChange = $delivery->sourceChange;
            $version = $userStream->current_version + 1;

            $derivedChange = EmailLiveProjectionChange::create([
                'stream_id' => $userStream->id,
                'version' => $version,
                'email_account_id' => $sourceChange->email_account_id,
                'change_types_json' => $sourceChange->change_types_json,
                'conversation_ids_json' => $sourceChange->conversation_ids_json,
                'placement_ids_json' => $sourceChange->placement_ids_json,
                'conversation_id_count' => $sourceChange->conversation_id_count,
                'placement_id_count' => $sourceChange->placement_id_count,
                'truncated' => $sourceChange->truncated,
                'publication_status' => EmailLiveProjectionChange::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            $userStream->update([
                'current_version' => $version,
                'last_changed_at' => now(),
            ]);

            $delivery->update([
                'status' => EmailLiveProjectionDelivery::STATUS_APPENDED,
                'derived_change_id' => $derivedChange->id,
                'derived_stream_id' => $userStream->id,
                'completed_at' => now(),
            ]);

            $this->broadcast($delivery->user_id, $derivedChange);
        });
    }

    private function broadcast(int $userId, EmailLiveProjectionChange $change): void
    {
        broadcast(new EmailProjectionInvalidated($userId, [
            'schema' => 1,
            'scope' => 'user',
            'from_version' => (string) ($change->version - 1),
            'to_version' => (string) $change->version,
            'change_types' => $change->change_types_json,
            'conversation_ids' => $change->conversation_ids_json ?? [],
            'placement_ids' => $change->placement_ids_json ?? [],
            'truncated' => $change->truncated,
        ]));
    }

    private function markAsFailed(EmailLiveProjectionChange $change, Exception $e): void
    {
        $change->update([
            'publication_status' => $change->attempt_count >= 3
                ? EmailLiveProjectionChange::STATUS_BLOCKED
                : EmailLiveProjectionChange::STATUS_PENDING,
            'next_attempt_at' => now()->addMinutes(pow(2, $change->attempt_count)),
            'error_code' => substr($e->getMessage(), 0, 80),
        ]);
    }
}
