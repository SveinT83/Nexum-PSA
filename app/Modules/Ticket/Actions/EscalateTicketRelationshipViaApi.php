<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Relationship\Actions\EscalateTicketToRelationship;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncEvent;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Reserve durably before network I/O. A lost/unknown outcome is read back and never blindly resent by transport retry. */
class EscalateTicketRelationshipViaApi
{
    public function handle(Ticket $ticket, User $actor, array $data): TicketEvent
    {
        [$receipt, $execute] = DB::transaction(function () use ($ticket, $actor, $data) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            app(TicketMutationScopeGuard::class)->assert($ticket);
            $relationship = NexumRelationship::query()->findOrFail($data['relationship_id']);
            app(EscalateTicketToRelationship::class)->validateTarget($ticket, $relationship);
            $key = hash('sha256', $data['idempotency_key']);
            $fingerprint = hash('sha256', json_encode(['ticket_id' => $ticket->id, 'relationship_id' => (int) $relationship->id], JSON_THROW_ON_ERROR));
            $existing = TicketEvent::query()->where('type', 'api_relationship_escalation')->where('actor_id', $actor->id)->where('metadata->request_key_hash', $key)->first();
            if ($existing) {
                abort_unless((int) $existing->ticket_id === (int) $ticket->id && hash_equals((string) data_get($existing->metadata, 'request_fingerprint'), $fingerprint), 409, 'The escalation key was already used for a different destination.');

                return [$existing, false];
            }
            $inFlight = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'api_relationship_escalation')->where('metadata->relationship_id', $relationship->id)
                ->whereIn('metadata->operation_status', ['reserved', 'unresolved'])->exists();
            abort_if($inFlight, 409, 'An outbound escalation is pending or unresolved. Read back and reconcile that outcome before starting another attempt.');
            $receipt = TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $actor->id, 'type' => 'api_relationship_escalation', 'message' => 'Ticket relationship escalation reserved through API.',
                'metadata' => ['relationship_id' => $relationship->id, 'request_key_hash' => $key, 'request_fingerprint' => $fingerprint, 'operation_status' => 'reserved']]);

            return [$receipt, true];
        }, 3);
        if (! $execute) {
            return $receipt;
        }
        $status = 'unresolved';
        $reason = 'OUTBOUND_RESULT_UNRESOLVED';
        $link = null;
        try {
            $ticket = $ticket->fresh();
            $relationship = NexumRelationship::query()->findOrFail($data['relationship_id']);
            $link = app(EscalateTicketToRelationship::class)->handle($ticket->load(['client', 'status', 'priority']), $relationship, $actor);
            $error = NexumSyncEvent::query()->where('sync_link_id', $link->id)->where('event_type', 'ticket_escalated')->orderByDesc('id')->value('error_code');
            if ($link->sync_status === 'synced') {
                $status = 'completed';
                $reason = null;
            } elseif ($error === 'missing_credentials' || (is_string($error) && preg_match('/\Aremote_http_4[0-9]{2}\z/', $error))) {
                $status = 'failed_pre_send';
                $reason = 'DESTINATION_REFUSED_OR_NOT_READY';
            }
        } catch (ValidationException) {
            $status = 'failed_pre_send';
            $reason = 'DESTINATION_POLICY_CHANGED';
        } catch (Throwable $exception) {
            // Never log credentials, remote response bodies or exception messages from an outbound transport.
            Log::warning('Ticket relationship API outcome requires reconciliation.', ['receipt_id' => $receipt->id, 'exception_class' => $exception::class]);
        }
        $receipt->forceFill(['metadata' => array_merge($receipt->metadata ?? [], ['operation_status' => $status, 'safe_reason_code' => $reason, 'sync_link_id' => $link?->id])])->save();

        return $receipt->refresh();
    }
}
