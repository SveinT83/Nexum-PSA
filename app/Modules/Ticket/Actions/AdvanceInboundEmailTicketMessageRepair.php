<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/** Advance one bounded legacy metadata-to-column repair page. */
final class AdvanceInboundEmailTicketMessageRepair
{
    public const TABLE = 'notification_inbound_ticket_message_repairs';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const BATCH_SIZE = 100;

    public function __construct(
        private readonly InboundEmailNotificationFanoutReadiness $fanoutReadiness,
    ) {}

    /** @return array{status:string,cursor_id:int,through_id:int,processed:int,error_code:?string} */
    public function handle(): array
    {
        if (! $this->fanoutReadiness->ready() || ! Schema::hasTable(self::TABLE)) {
            return $this->result(self::STATUS_FAILED, 0, 0, 0, 'repair_schema_unavailable');
        }

        try {
            return DB::transaction(function (): array {
                $state = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
                if (! $state) {
                    return $this->result(self::STATUS_FAILED, 0, 0, 0, 'repair_state_missing');
                }
                if (in_array($state->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
                    return $this->result(
                        (string) $state->status,
                        (int) $state->cursor_id,
                        (int) $state->through_id,
                        0,
                        $state->error_code !== null ? (string) $state->error_code : null,
                    );
                }

                $token = hash('sha256', random_bytes(32));
                if ($state->status === self::STATUS_PENDING) {
                    $messages = TicketMessage::query()
                        ->withTrashed()
                        ->where('id', '>', $state->cursor_id)
                        ->where('id', '<=', $state->through_id)
                        ->orderBy('id')
                        ->limit(self::BATCH_SIZE)
                        ->get([
                            'id',
                            'ticket_id',
                            'source_inbound_email_message_id',
                            'inbound_email_message_id',
                            'metadata',
                        ]);
                    $lastId = (int) ($messages->last()?->id ?? $state->through_id);
                    $pageThroughId = $messages->count() < self::BATCH_SIZE
                        ? (int) $state->through_id
                        : $lastId;
                    $claimed = DB::table(self::TABLE)
                        ->where('id', 1)
                        ->where('status', self::STATUS_PENDING)
                        ->where('cursor_id', $state->cursor_id)
                        ->update([
                            'status' => self::STATUS_RUNNING,
                            'claim_token' => $token,
                            'page_through_id' => $pageThroughId,
                            'page_row_count' => $messages->count(),
                            'last_attempt_at' => now(),
                            'updated_at' => now(),
                        ]);
                    if ($claimed !== 1) {
                        throw new RuntimeException('repair_page_attestation_conflict');
                    }
                } elseif ($state->status === self::STATUS_RUNNING) {
                    $pageThroughId = (int) ($state->page_through_id ?? 0);
                    $expectedRows = (int) ($state->page_row_count ?? -1);
                    $reclaimed = DB::table(self::TABLE)
                        ->where('id', 1)
                        ->where('status', self::STATUS_RUNNING)
                        ->where('claim_token', $state->claim_token)
                        ->where('cursor_id', $state->cursor_id)
                        ->where('page_through_id', $pageThroughId)
                        ->where('page_row_count', $expectedRows)
                        ->update([
                            'claim_token' => $token,
                            'last_attempt_at' => now(),
                            'updated_at' => now(),
                        ]);
                    if ($reclaimed !== 1) {
                        throw new RuntimeException('repair_page_attestation_conflict');
                    }
                    $messages = TicketMessage::query()
                        ->withTrashed()
                        ->where('id', '>', $state->cursor_id)
                        ->where('id', '<=', $pageThroughId)
                        ->orderBy('id')
                        ->limit(self::BATCH_SIZE + 1)
                        ->get([
                            'id',
                            'ticket_id',
                            'source_inbound_email_message_id',
                            'inbound_email_message_id',
                            'metadata',
                        ]);
                } else {
                    throw new RuntimeException('repair_page_attestation_conflict');
                }

                $messageIds = $messages->pluck('id')->map(fn ($id): int => (int) $id)->all();
                $emailIds = $messages
                    ->map(fn (TicketMessage $message): ?int => $this->candidateEmailId($message))
                    ->filter()
                    ->unique()
                    ->sort()
                    ->values()
                    ->all();
                $emails = EmailMessage::query()
                    ->withTrashed()
                    ->whereIn('id', $emailIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
                $messages = TicketMessage::query()
                    ->withTrashed()
                    ->whereIn('id', $messageIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get([
                        'id',
                        'ticket_id',
                        'source_inbound_email_message_id',
                        'inbound_email_message_id',
                        'metadata',
                    ]);
                $expectedRows = $state->status === self::STATUS_RUNNING
                    ? (int) $state->page_row_count
                    : count($messageIds);
                if ($messages->pluck('id')->map(fn ($id): int => (int) $id)->all() !== $messageIds
                    || $messages->count() !== $expectedRows
                    || ($expectedRows > 0 && (int) $messages->last()?->id !== $pageThroughId)) {
                    throw new RuntimeException('repair_page_attestation_conflict');
                }

                foreach ($messages as $ticketMessage) {
                    $metadata = is_array($ticketMessage->metadata) ? $ticketMessage->metadata : [];
                    $hasMetadataEmailId = array_key_exists('email_message_id', $metadata);
                    $rawMetadataEmailId = $metadata['email_message_id'] ?? null;
                    $metadataEmailId = is_int($rawMetadataEmailId) && $rawMetadataEmailId >= 1
                        ? $rawMetadataEmailId
                        : null;
                    $sourcePointerId = (int) ($ticketMessage->source_inbound_email_message_id ?? 0);
                    $livePointerId = (int) ($ticketMessage->inbound_email_message_id ?? 0);
                    if ($hasMetadataEmailId && $metadataEmailId === null) {
                        throw new RuntimeException('repair_pointer_metadata_invalid');
                    }
                    if (($sourcePointerId < 1 && $livePointerId > 0)
                        || ($sourcePointerId > 0 && $livePointerId > 0
                            && $sourcePointerId !== $livePointerId)) {
                        throw new RuntimeException('repair_pointer_metadata_conflict');
                    }
                    if (! $hasMetadataEmailId && $sourcePointerId < 1) {
                        continue;
                    }

                    $emailId = $sourcePointerId > 0 ? $sourcePointerId : (int) $metadataEmailId;
                    if ($metadataEmailId !== null && $metadataEmailId !== $emailId) {
                        throw new RuntimeException('repair_pointer_metadata_conflict');
                    }

                    $email = $emails->get($emailId);
                    if (! $email || (int) $email->ticket_id !== (int) $ticketMessage->ticket_id) {
                        throw new RuntimeException('repair_email_ticket_conflict');
                    }

                    $duplicate = TicketMessage::query()
                        ->withTrashed()
                        ->where('source_inbound_email_message_id', $emailId)
                        ->whereKeyNot($ticketMessage->id)
                        ->exists();
                    if ($duplicate) {
                        throw new RuntimeException('repair_duplicate_email_pointer');
                    }

                    if ($sourcePointerId === 0) {
                        $ticketMessage->forceFill([
                            'source_inbound_email_message_id' => $emailId,
                            'inbound_email_message_id' => $emailId,
                        ])->save();
                    }
                }

                $completed = $pageThroughId >= (int) $state->through_id;
                $committed = DB::table(self::TABLE)
                    ->where('id', 1)
                    ->where('status', self::STATUS_RUNNING)
                    ->where('claim_token', $token)
                    ->where('cursor_id', $state->cursor_id)
                    ->where('page_through_id', $pageThroughId)
                    ->where('page_row_count', $messages->count())
                    ->update([
                        'status' => $completed ? self::STATUS_COMPLETED : self::STATUS_PENDING,
                        'cursor_id' => $pageThroughId,
                        'claim_token' => null,
                        'page_through_id' => null,
                        'page_row_count' => null,
                        'page_count' => (int) $state->page_count + 1,
                        'completed_at' => $completed ? now() : null,
                        'error_code' => null,
                        'updated_at' => now(),
                    ]);
                if ($committed !== 1) {
                    throw new RuntimeException('repair_page_attestation_conflict');
                }

                return $this->result(
                    $completed ? self::STATUS_COMPLETED : self::STATUS_PENDING,
                    $pageThroughId,
                    (int) $state->through_id,
                    $messages->count(),
                    null,
                );
            }, 3);
        } catch (Throwable $exception) {
            $semanticError = in_array($exception->getMessage(), [
                'repair_pointer_metadata_conflict',
                'repair_pointer_metadata_invalid',
                'repair_email_ticket_conflict',
                'repair_duplicate_email_pointer',
                'repair_page_attestation_conflict',
            ], true);
            if (! $semanticError) {
                // The page transaction already rolled back. Preserve the
                // durable cursor byte-for-byte so an operator can retry the
                // exact page after the sanitized nonzero command result.
                $state = DB::table(self::TABLE)->where('id', 1)->first();

                return $this->result(
                    (string) ($state->status ?? self::STATUS_PENDING),
                    (int) ($state->cursor_id ?? 0),
                    (int) ($state->through_id ?? 0),
                    0,
                    'repair_page_failed',
                );
            }

            $errorCode = $exception->getMessage();
            $state = DB::transaction(function () use ($errorCode): ?object {
                $current = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
                if (! $current || in_array(
                    $current->status,
                    [self::STATUS_COMPLETED, self::STATUS_FAILED],
                    true,
                )) {
                    return $current;
                }

                if (! in_array($current->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true)) {
                    return $current;
                }

                DB::table(self::TABLE)->where('id', 1)->where('status', $current->status)->update([
                    'status' => self::STATUS_FAILED,
                    'claim_token' => null,
                    'page_through_id' => null,
                    'page_row_count' => null,
                    'last_attempt_at' => now(),
                    'completed_at' => now(),
                    'error_code' => $errorCode,
                    'updated_at' => now(),
                ]);

                return DB::table(self::TABLE)->where('id', 1)->first();
            }, 3);

            return $this->result(
                (string) ($state->status ?? self::STATUS_FAILED),
                (int) ($state->cursor_id ?? 0),
                (int) ($state->through_id ?? 0),
                0,
                $state?->error_code !== null ? (string) $state->error_code : $errorCode,
            );
        }
    }

    private function candidateEmailId(TicketMessage $message): ?int
    {
        $sourceId = (int) ($message->source_inbound_email_message_id ?? 0);
        if ($sourceId > 0) {
            return $sourceId;
        }

        $liveId = (int) ($message->inbound_email_message_id ?? 0);
        if ($liveId > 0) {
            return $liveId;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $raw = $metadata['email_message_id'] ?? null;

        return is_int($raw) && $raw >= 1 ? $raw : null;
    }

    /** @return array{status:string,cursor_id:int,through_id:int,processed:int,error_code:?string} */
    private function result(
        string $status,
        int $cursorId,
        int $throughId,
        int $processed,
        ?string $errorCode,
    ): array {
        return [
            'status' => $status,
            'cursor_id' => $cursorId,
            'through_id' => $throughId,
            'processed' => $processed,
            'error_code' => $errorCode,
        ];
    }
}
