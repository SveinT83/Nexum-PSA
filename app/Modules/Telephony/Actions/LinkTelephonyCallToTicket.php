<?php

namespace App\Modules\Telephony\Actions;

use App\Modules\Telephony\Models\TelephonyCall;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;

class LinkTelephonyCallToTicket
{
    public function __construct(
        private readonly AddTicketMessage $addTicketMessage,
    ) {
    }

    public function handle(TelephonyCall $call, Ticket $ticket, ?string $note = null): void
    {
        DB::transaction(function () use ($call, $ticket, $note): void {
            $note = trim((string) ($note ?? $call->notes ?? ''));

            if ($note !== '') {
                $this->addTicketMessage->handle($ticket, [
                    'type' => 'internal_note',
                    'visibility' => 'internal',
                    'body' => $note,
                    'metadata' => [
                        'created_from' => 'telephony_call',
                        'telephony_call_id' => $call->id,
                    ],
                ], $call->answeredBy);
            }

            $call->forceFill([
                'linked_ticket_id' => $ticket->id,
                'notes' => $note !== '' ? $note : $call->notes,
                'status' => 'linked',
            ])->save();
        });
    }
}
