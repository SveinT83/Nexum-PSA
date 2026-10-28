<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketFormValues;
use App\Modules\Ticket\Support\TicketPortalPolicy;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Normalize supported form choices without enabling any dormant runtime capability. */
class TicketApiPayload
{
    public function prepare(Request $request, array $data, ?Ticket $ticket = null): array
    {
        if ($ticket) {
            app(TicketMutationScopeGuard::class)->assert($ticket);
        }
        $merged = array_merge($ticket ? $ticket->only(['client_id', 'site_id', 'contact_id', 'asset_id']) : [], $data);
        $clientId = $merged['client_id'] ?? null;
        $contactId = $merged['contact_id'] ?? null;
        if ($contactId && ! $clientId) {
            throw ValidationException::withMessages(['client_id' => 'Select a client before selecting a contact.']);
        }
        if (! $ticket || array_intersect(['client_id', 'contact_id', 'site_id', 'asset_id'], array_keys($data))) {
            $data['site_id'] = app(TicketFormValues::class)->resolveSiteId($clientId, $merged['site_id'] ?? null, $contactId, $merged['asset_id'] ?? null);
        }
        $scheduleFields = ['is_scheduled', 'planned_start_at', 'planned_end_at', 'timezone', 'schedule_type', 'recurrence_rule', 'recurrence_ends_at', 'task_template_group_id', 'sla_mode'];
        if (array_intersect($scheduleFields, array_keys($data))) {
            $schedule = $ticket?->schedule;
            $data = array_merge($schedule ? $schedule->only(array_diff($scheduleFields, ['is_scheduled'])) : [], ['is_scheduled' => (bool) $schedule], $data);
            if ($data['is_scheduled']) {
                if (empty($data['planned_start_at'])) {
                    throw ValidationException::withMessages(['planned_start_at' => 'Select a planned start for a scheduled Ticket.']);
                }
                $start = \Carbon\Carbon::parse($data['planned_start_at']);
                if (! empty($data['planned_end_at']) && \Carbon\Carbon::parse($data['planned_end_at'])->lt($start)) {
                    throw ValidationException::withMessages(['planned_end_at' => 'The planned end must be after the planned start.']);
                }
                if (($data['schedule_type'] ?? 'one_time') === 'recurring' && empty($data['recurrence_rule'])) {
                    throw ValidationException::withMessages(['recurrence_rule' => 'Select a recurrence rule for a recurring Ticket.']);
                }
                if (! empty($data['recurrence_ends_at']) && \Carbon\Carbon::parse($data['recurrence_ends_at'])->lt($start)) {
                    throw ValidationException::withMessages(['recurrence_ends_at' => 'Recurrence must end after the planned start.']);
                }
            }
            if (! $data['is_scheduled'] || ($data['schedule_type'] ?? 'one_time') !== 'recurring') {
                $data['task_template_group_id'] = null;
            }
        }
        if (($data['customer_portal_visibility'] ?? null) === TicketPortalPolicy::VISIBILITY_PUBLISHED && $clientId) {
            abort_unless($request->user()->tokenCan('tickets.portal.publish'), 403, 'Portal publication scope is required.');
            app(DomainApiAuthorization::class)->authorize($request, 'ticket.update');
        }

        return $data;
    }
}
