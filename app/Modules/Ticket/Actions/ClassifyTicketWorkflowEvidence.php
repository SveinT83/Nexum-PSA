<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassifyTicketWorkflowEvidence
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    public function handle(Ticket $ticket, array $data, User $actor): TicketWorkflowEvidence
    {
        if (! $actor->can('ticket.evidence_classify')) {
            throw ValidationException::withMessages(['evidence' => 'Evidence classification permission is required.']);
        }

        if ($reason = $this->guard->reason($ticket, TicketAction::CLASSIFY_EVIDENCE, $actor)) {
            throw ValidationException::withMessages(['evidence' => $reason]);
        }

        $type = (string) ($data['evidence_type'] ?? '');
        if (! in_array($type, ['customer_response', 'signature', 'manual_approval'], true)) {
            throw ValidationException::withMessages(['evidence_type' => 'Unsupported workflow evidence type.']);
        }

        $source = $this->source($ticket, (string) ($data['source_type'] ?? ''), (int) ($data['source_id'] ?? 0));

        if ($type === 'customer_response' && ! $source instanceof TicketMessage) {
            throw ValidationException::withMessages(['source_type' => 'Customer response evidence must reference a Ticket message.']);
        }

        if ($type === 'customer_response' && $source->author_type === 'user') {
            throw ValidationException::withMessages(['source_id' => 'A technician message cannot be classified as a customer response.']);
        }

        if ($type === 'signature' && ! $source instanceof TicketAttachment) {
            throw ValidationException::withMessages(['source_type' => 'Signature evidence must reference a Ticket attachment.']);
        }

        $created = false;
        $evidence = DB::transaction(function () use ($ticket, $data, $actor, $source, $type, &$created): TicketWorkflowEvidence {
            $fingerprint = $this->fingerprint($source);

            $existing = $ticket->workflowEvidence()
                ->where('evidence_type', $type)
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNull('invalidated_at')
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = true;
            $evidence = $ticket->workflowEvidence()->create([
                'evidence_type' => $type,
                'scope_key' => $data['scope_key'] ?? null,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'fingerprint' => $fingerprint,
                'subject_name' => $data['subject_name'] ?? null,
                'evidenced_at' => $data['evidenced_at'] ?? now(),
                'created_by' => $actor->id,
                'comment' => $data['comment'] ?? null,
                'metadata' => $data['metadata'] ?? [],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'workflow_evidence_classified',
                'message' => ucfirst(str_replace('_', ' ', $type)).' classified as workflow evidence.',
                'after' => ['evidence_id' => $evidence->id, 'scope_key' => $evidence->scope_key],
            ]);

            app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'Workflow evidence changed.', $actor);

            return $evidence;
        });

        if ($created) {
            app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::CLASSIFY_EVIDENCE, $actor);
        }

        return $evidence;
    }

    private function source(Ticket $ticket, string $sourceType, int $sourceId): Model
    {
        return match ($sourceType) {
            'message', TicketMessage::class => $ticket->messages()->findOrFail($sourceId),
            'attachment', TicketAttachment::class => $ticket->attachments()->findOrFail($sourceId),
            default => throw ValidationException::withMessages(['source_type' => 'Evidence source must be a Ticket message or attachment.']),
        };
    }

    private function fingerprint(Model $source): string
    {
        if ($source instanceof TicketAttachment) {
            return hash('sha256', implode('|', [$source->id, $source->checksum_sha1, $source->size_bytes, $source->path]));
        }

        return hash('sha256', implode('|', [$source->id, $source->updated_at?->timestamp, $source->body]));
    }
}
