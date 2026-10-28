<?php

namespace App\Modules\Task\Services;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Support\TaskFormValues;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Validate owner/context and form-only fields before any Task or related record is written. */
class TaskApiPayload
{
    public function prepare(Request $request, array $data, ?Model $owner, ?Task $task = null): array
    {
        $clientId = array_key_exists('client_id', $data) ? $data['client_id'] : ($task?->client_id ?? ($owner instanceof Ticket ? $owner->client_id : ($owner instanceof Client ? $owner->id : null)));
        $siteId = array_key_exists('site_id', $data) ? $data['site_id'] : $task?->site_id;
        if ($owner instanceof Ticket) {
            app(TicketMutationScopeGuard::class)->assert($owner);
            app(DomainApiAuthorization::class)->authorize($request, 'ticket.view');
            abort_unless($request->user()->tokenCan('tickets.read'), 403, 'Ticket read scope is required for a Ticket-owned Task.');
            if ((int) $clientId !== (int) $owner->client_id) {
                throw ValidationException::withMessages(['client_id' => 'The Task client must match its Ticket owner.']);
            }
        }
        if ($owner instanceof Client && (int) $clientId !== (int) $owner->id) {
            throw ValidationException::withMessages(['client_id' => 'The Task client must match its Client owner.']);
        }
        if ($siteId && (! $clientId || ! ClientSite::query()->whereKey($siteId)->where('client_id', $clientId)->exists())) {
            throw ValidationException::withMessages(['site_id' => 'The selected site does not belong to the selected client.']);
        }
        if (! empty($data['parent_id'])) {
            $parent = Task::query()->findOrFail($data['parent_id']);
            app(TaskApiScope::class)->assert($parent, $request->user());
            if ((int) $parent->client_id !== (int) $clientId) {
                throw ValidationException::withMessages(['parent_id' => 'The parent Task must use the same client context.']);
            }
            $seen = [];
            while ($parent) {
                if (($task && (int) $parent->id === (int) $task->id) || isset($seen[$parent->id])) {
                    throw ValidationException::withMessages(['parent_id' => 'Task hierarchy cannot contain a cycle.']);
                }
                $seen[$parent->id] = true;
                $parent = $parent->parent_id ? Task::query()->find($parent->parent_id) : null;
            }
        }
        $start = $data['scheduled_start_at'] ?? $task?->scheduled_start_at;
        $end = $data['scheduled_end_at'] ?? $task?->scheduled_end_at;
        if ($start && $end && \Carbon\Carbon::parse($end)->lt(\Carbon\Carbon::parse($start))) {
            throw ValidationException::withMessages(['scheduled_end_at' => 'The scheduled end must be after the scheduled start.']);
        }
        if (array_key_exists('metadata', $data) && array_intersect(['ticket_rate_key', 'ticket_rate_label'], array_keys($data['metadata'] ?? []))) {
            throw ValidationException::withMessages(['metadata' => 'Select Ticket rates through ticket_rate_key, not raw metadata.']);
        }
        if (array_key_exists('ticket_rate_key', $data)) {
            $metadata = array_merge($task?->metadata ?? [], $data['metadata'] ?? []);
            unset($metadata['ticket_rate_key'], $metadata['ticket_rate_label']);
            if (filled($data['ticket_rate_key'])) {
                if (! $owner instanceof Ticket || ! $rate = app(TicketTimeRateOptions::class)->findForTicket($owner, $data['ticket_rate_key'])) {
                    throw ValidationException::withMessages(['ticket_rate_key' => 'Select an available rate for this Ticket Task.']);
                }
                $metadata['ticket_rate_key'] = $rate['key'];
                $metadata['ticket_rate_label'] = $rate['label'];
            }
            $data['metadata'] = $metadata;
        }
        if (array_key_exists('checklist_text', $data)) {
            $data['checklist'] = app(TaskFormValues::class)->parseChecklist($data['checklist_text']);
        }

        return $data;
    }

    public function sync(Task $task, array $data): void
    {
        if (array_key_exists('tag_names', $data)) {
            $task->tags()->syncWithPivotValues(app(TaskFormValues::class)->resolveTagIds($data['tag_names'] ?? []), ['module' => 'Task']);
        }
    }
}
