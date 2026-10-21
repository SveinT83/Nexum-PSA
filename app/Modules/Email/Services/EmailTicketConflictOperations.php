<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\ResolveEmailTicketCorrelationConflict;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Http\Request;

final class EmailTicketConflictOperations
{
    public function index(): array
    {
        $conflicts = EmailTicketCorrelationConflict::query()
            ->where('status', EmailTicketCorrelationConflict::STATUS_PENDING)
            ->with(['message.account'])
            ->oldest('detected_at')
            ->paginate(50);
        $ticketIds = $conflicts->getCollection()
            ->flatMap(fn (EmailTicketCorrelationConflict $conflict): array => $conflict->candidate_ticket_ids ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->all();
        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->get()
            ->keyBy('id');

        return compact('conflicts', 'tickets');
    }

    public function resolve(
        EmailTicketCorrelationConflict $conflict,
        Request $request,
        ResolveEmailTicketCorrelationConflict $resolve,
    ): array {
        $data = $request->validate([
            'ticket_id' => ['required', 'integer', 'exists:tickets,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $resolve->handle(
            $conflict,
            Ticket::query()->findOrFail($data['ticket_id']),
            $request->user(),
            $data['reason'],
        );

        return ['conflict' => $conflict->fresh()];
    }
}
