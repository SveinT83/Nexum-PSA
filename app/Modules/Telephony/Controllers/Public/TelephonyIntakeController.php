<?php

namespace App\Modules\Telephony\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Telephony\Actions\CreateTicketFromTelephonyCall;
use App\Modules\Telephony\Actions\EnsureTelephonyToken;
use App\Modules\Telephony\Actions\LinkTelephonyCallToTicket;
use App\Modules\Telephony\Actions\RecordTelephonyCall;
use App\Modules\Telephony\Models\TelephonyCall;
use App\Modules\Telephony\Models\TelephonyToken;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TelephonyIntakeController extends Controller
{
    public function show(string $token, Request $request, RecordTelephonyCall $recordCall): View
    {
        $call = $recordCall->handle($token, $request);

        return $this->view($token, $call);
    }

    public function call(string $token, TelephonyCall $call): View
    {
        $this->authorizeTokenCall($token, $call);

        return $this->view($token, $call);
    }

    public function updateNote(string $token, TelephonyCall $call, Request $request): RedirectResponse
    {
        $this->authorizeTokenCall($token, $call);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:10000'],
        ]);

        $call->forceFill([
            'notes' => $data['notes'] ?? null,
        ])->save();

        return redirect()
            ->route('telephony.intake.call', ['token' => $token, 'call' => $call])
            ->with('success', 'Call note saved.');
    }

    public function createTicket(
        string $token,
        TelephonyCall $call,
        Request $request,
        CreateTicketFromTelephonyCall $createTicket,
    ): RedirectResponse {
        $telephonyToken = $this->authorizeTokenCall($token, $call);
        $this->authorizeTicketPermission($telephonyToken, 'ticket.create');

        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
        ]);

        $ticket = $createTicket->handle($call->refresh(), $data);

        return redirect()
            ->route('telephony.intake.call', ['token' => $token, 'call' => $call])
            ->with('success', 'Ticket '.$ticket->ticket_key.' created from call.');
    }

    public function linkTicket(
        string $token,
        TelephonyCall $call,
        Request $request,
        LinkTelephonyCallToTicket $linkCall,
    ): RedirectResponse {
        $telephonyToken = $this->authorizeTokenCall($token, $call);
        $this->authorizeTicketPermission($telephonyToken, 'ticket.view');
        $this->authorizeTicketPermission($telephonyToken, 'ticket.note_internal');

        $data = $request->validate([
            'ticket_key' => ['required', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:10000'],
        ]);

        $ticket = $this->scopedTicketByKey($call->refresh(), $data['ticket_key']);

        $linkCall->handle($call->refresh(), $ticket, $data['note'] ?? null);

        return redirect()
            ->route('telephony.intake.call', ['token' => $token, 'call' => $call])
            ->with('success', 'Call linked to ticket '.$ticket->ticket_key.'.');
    }

    private function view(string $token, TelephonyCall $call): View
    {
        $call = $call->fresh([
            'answeredBy',
            'contact.emails',
            'contact.phones',
            'clientUser.site.client',
            'client',
            'site',
            'linkedTicket.status',
            'linkedTicket.priority',
        ]);

        return view('telephony::Public.intake', [
            'token' => $token,
            'call' => $call,
            'openTickets' => $this->relatedTickets($call, closed: false)->take(8),
            'recentClosedTickets' => $this->relatedTickets($call, closed: true)->take(5),
        ]);
    }

    private function authorizeTokenCall(string $plainToken, TelephonyCall $call): TelephonyToken
    {
        $token = app(EnsureTelephonyToken::class)->findByPlainToken($plainToken);

        abort_unless(
            $token
                && $token->user?->isActive()
                && $token->user->can('telephony.view')
                && (int) $call->answered_by_user_id === (int) $token->user_id,
            404
        );

        return $token;
    }

    private function authorizeTicketPermission(TelephonyToken $token, string $permission): void
    {
        abort_unless($token->user?->can($permission), 403);
    }

    private function scopedTicketByKey(TelephonyCall $call, string $ticketKey): Ticket
    {
        abort_unless($call->client_id || $call->client_user_id || $call->site_id, 404);

        return Ticket::query()
            ->where('ticket_key', $ticketKey)
            ->where(function ($query) use ($call): void {
                if ($call->client_id) {
                    $query->orWhere('client_id', $call->client_id);
                }

                if ($call->client_user_id) {
                    $query->orWhere('contact_id', $call->client_user_id);
                }

                if ($call->site_id) {
                    $query->orWhere('site_id', $call->site_id);
                }
            })
            ->firstOrFail();
    }

    private function relatedTickets(TelephonyCall $call, bool $closed): Collection
    {
        if (! $call->client_id && ! $call->client_user_id) {
            return collect();
        }

        return Ticket::query()
            ->with(['status', 'priority'])
            ->where(function ($query) use ($call): void {
                if ($call->client_id) {
                    $query->orWhere('client_id', $call->client_id);
                }

                if ($call->client_user_id) {
                    $query->orWhere('contact_id', $call->client_user_id);
                }
            })
            ->whereHas('status', fn ($query) => $query->where('is_closed', $closed))
            ->latest('updated_at')
            ->limit($closed ? 5 : 8)
            ->get();
    }
}
