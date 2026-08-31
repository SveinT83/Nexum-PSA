<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class InboundEmailTicketCorrelationService
{
    public function __construct(
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
    ) {}

    /**
     * Correlate one inbound message without guessing when independent evidence
     * identifies different Tickets. A true result means the message was linked
     * or must remain blocked for explicit conflict resolution.
     */
    public function correlate(EmailMessage $message): bool
    {
        $message = $message->fresh() ?? $message;

        if ($message->ticket_id !== null) {
            return true;
        }

        $evidence = $this->evidence($message);
        $candidateIds = collect($evidence)
            ->flatten()
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($candidateIds->isEmpty()) {
            return false;
        }

        if (Schema::hasTable('email_ticket_correlation_conflicts')) {
            $existing = EmailTicketCorrelationConflict::query()
                ->where('email_message_id', $message->id)
                ->first();

            if ($existing?->status === EmailTicketCorrelationConflict::STATUS_PENDING) {
                return true;
            }

            if ($existing?->status === EmailTicketCorrelationConflict::STATUS_RESOLVED) {
                $ticket = $existing->resolved_ticket_id
                    ? Ticket::query()->find($existing->resolved_ticket_id)
                    : null;

                if ($ticket) {
                    $this->linkInboundEmailToTicket->handle($message, $ticket);
                }

                return true;
            }
        }

        if ($candidateIds->count() > 1) {
            $this->recordConflict($message, $candidateIds->all(), $evidence);

            return true;
        }

        $ticket = Ticket::query()->find($candidateIds->first());

        if (! $ticket) {
            return false;
        }

        $this->linkInboundEmailToTicket->handle($message, $ticket);

        return true;
    }

    /** @return array{durable_link: list<int>, rfc_headers: list<int>, subject_key: list<int>} */
    private function evidence(EmailMessage $message): array
    {
        return [
            'durable_link' => $this->durableLinkTicketIds($message),
            'rfc_headers' => $this->headerTicketIds($message),
            'subject_key' => $this->subjectTicketIds($message),
        ];
    }

    /** @return list<int> */
    private function durableLinkTicketIds(EmailMessage $message): array
    {
        if (! Schema::hasTable('email_ticket_conversation_links')) {
            return [];
        }

        return EmailTicketConversationLink::query()
            ->where('email_message_id', $message->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->pluck('ticket_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function headerTicketIds(EmailMessage $message): array
    {
        $messageIds = $this->referencedMessageIds($message);

        if ($messageIds === []) {
            return [];
        }

        $ticketMessageIds = EmailLog::query()
            ->where('direction', 'outbound')
            ->where('scope', 'tickets')
            ->whereIn('rfc_message_id', $messageIds)
            ->get()
            ->map(fn (EmailLog $log): int => (int) ($log->context_json['ticket_message_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();

        if ($ticketMessageIds->isEmpty()) {
            return [];
        }

        return TicketMessage::query()
            ->whereIn('id', $ticketMessageIds->all())
            ->pluck('ticket_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function subjectTicketIds(EmailMessage $message): array
    {
        if (! preg_match('/\b(TD-\d{4}-\d{6})\b/i', (string) $message->subject, $matches)) {
            return [];
        }

        return Ticket::query()
            ->where('ticket_key', strtoupper($matches[1]))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return list<string> */
    private function referencedMessageIds(EmailMessage $message): array
    {
        $value = trim((string) $message->in_reply_to.' '.(string) $message->references);

        if ($value === '') {
            return [];
        }

        preg_match_all('/<([^<>\s]+)>/', $value, $bracketedMatches);
        preg_match_all('/(?<![<\w])[^\s<>;,]+@[^\s<>;,]+(?![>\w])/', $value, $bareMatches);

        return collect($bracketedMatches[1] ?? [])
            ->merge($bareMatches[0] ?? [])
            ->map(fn ($messageId) => trim($messageId, " \t\n\r\0\x0B<>;,"))
            ->filter()
            ->flatMap(fn (string $messageId): array => [$messageId, '<'.$messageId.'>'])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Store identifiers and correlation methods only. Subjects, addresses,
     * bodies and raw headers deliberately remain outside the audit record.
     *
     * @param  list<int>  $candidateIds
     * @param  array{durable_link: list<int>, rfc_headers: list<int>, subject_key: list<int>}  $evidence
     */
    private function recordConflict(EmailMessage $message, array $candidateIds, array $evidence): void
    {
        if (! Schema::hasTable('email_ticket_correlation_conflicts')) {
            Log::warning('Inbound Email Ticket correlation is blocked until the conflict migration is applied.', [
                'email_message_id' => $message->id,
                'candidate_count' => count($candidateIds),
            ]);

            return;
        }

        DB::transaction(function () use ($message, $candidateIds, $evidence): void {
            EmailMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();

            EmailTicketCorrelationConflict::query()->firstOrCreate(
                ['email_message_id' => $message->id],
                [
                    'status' => EmailTicketCorrelationConflict::STATUS_PENDING,
                    'candidate_ticket_ids' => $candidateIds,
                    'evidence' => $evidence,
                    'detected_at' => now(),
                ],
            );
        });
    }
}
