<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Telephony\Models\TelephonyCall;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;

class CreateTicketFromTelephonyCall
{
    public function __construct(
        private readonly StoreTicket $storeTicket,
    ) {
    }

    public function handle(TelephonyCall $call, array $data): Ticket
    {
        return DB::transaction(function () use ($call, $data): Ticket {
            $actor = $call->answeredBy;
            $note = trim((string) ($data['description'] ?? $call->notes ?? ''));
            $subject = trim((string) ($data['subject'] ?? ''));

            if ($subject === '') {
                $subject = 'Phone call from '.($call->contact?->display_name ?: $call->caller_number_normalized ?: $call->caller_number_raw ?: 'unknown caller');
            }

            $ticket = $this->storeTicket->handle([
                'subject' => $subject,
                'description' => $note !== '' ? $note : null,
                'client_id' => $call->client_id,
                'site_id' => $call->site_id,
                'contact_id' => $call->client_user_id,
                'owner_id' => $actor?->id,
                'channel' => 'phone',
            ], $actor);

            $metadata = $ticket->metadata ?? [];
            $metadata['telephony_call_id'] = $call->id;
            $metadata['telephony_caller_number'] = $call->caller_number_normalized ?: $call->caller_number_raw;
            $ticket->forceFill(['metadata' => $metadata])->save();

            $call->forceFill([
                'linked_ticket_id' => $ticket->id,
                'notes' => $note !== '' ? $note : $call->notes,
                'status' => 'linked',
            ])->save();

            return $ticket->fresh(['messages']);
        });
    }
}
