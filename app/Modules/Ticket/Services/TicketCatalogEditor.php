<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Browser and API catalog edits share validation, protected references, defaults and atomic audit. */
class TicketCatalogEditor
{
    public function saveQueue(Request $request, ?TicketQueue $queue = null): TicketQueue
    {
        return DB::transaction(function () use ($request, $queue) {
            $before = $queue?->attributesToArray() ?? [];
            $data = $this->validatedQueue($request, $queue);
            if ($queue) {
                $queue->update($data);
            } else {
                $queue = TicketQueue::query()->create($data);
            }
            $this->ensureSingleDefaultQueue($queue);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.queue.saved', $queue, $before, $queue->refresh()->attributesToArray());

            return $queue;
        });
    }

    public function deleteQueue(Request $request, TicketQueue $queue): void
    {
        DB::transaction(function () use ($request, $queue) {
            $queue = TicketQueue::query()->lockForUpdate()->findOrFail($queue->id);
            if ($queue->tickets()->exists() || $this->ticketRuleReferences('set_queue', $queue->id)) {
                throw ValidationException::withMessages(['queue' => 'Queue cannot be deleted while tickets use it.']);
            }
            $before = $queue->attributesToArray();
            $queue->delete();
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.queue.deleted', $queue, $before, $queue->attributesToArray());
        });
    }

    public function saveType(Request $request, ?TicketType $type = null): TicketType
    {
        return DB::transaction(function () use ($request, $type) {
            $before = $type?->attributesToArray() ?? [];
            $data = $this->validatedType($request, $type);
            if ($type) {
                $type->update($data);
            } else {
                $type = TicketType::query()->create($data);
            }
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.type.saved', $type, $before, $type->refresh()->attributesToArray());

            return $type;
        });
    }

    public function deleteType(Request $request, TicketType $type): void
    {
        DB::transaction(function () use ($request, $type) {
            $type = TicketType::query()->lockForUpdate()->findOrFail($type->id);
            if (! $type->is_deletable || $type->tickets()->exists() || $this->ticketRuleReferences('set_ticket_type', $type->id)) {
                throw ValidationException::withMessages(['type' => 'Ticket type cannot be deleted while it is protected or in use.']);
            }
            $before = $type->attributesToArray();
            $type->delete();
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.type.deleted', $type, $before, $type->attributesToArray());
        });
    }

    public function saveStatus(Request $request, ?TicketStatus $status = null): TicketStatus
    {
        return DB::transaction(function () use ($request, $status) {
            $before = $status?->attributesToArray() ?? [];
            $data = $this->validatedStatus($request, $status);
            if ($status) {
                $status->update($data);
            } else {
                $status = TicketStatus::query()->create($data);
            }
            $this->ensureSingleDefaultStatus($status);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.status.saved', $status, $before, $status->refresh()->attributesToArray());

            return $status;
        });
    }

    public function deleteStatus(Request $request, TicketStatus $status): void
    {
        DB::transaction(function () use ($request, $status) {
            $status = TicketStatus::query()->lockForUpdate()->findOrFail($status->id);
            if ($status->tickets()->exists()) {
                throw ValidationException::withMessages(['status' => 'Ticket status cannot be deleted while tickets use it.']);
            }
            $before = $status->attributesToArray();
            $status->delete();
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.status.deleted', $status, $before, $status->attributesToArray());
        });
    }

    public function savePriority(Request $request, ?TicketPriority $priority = null): TicketPriority
    {
        return DB::transaction(function () use ($request, $priority) {
            $before = $priority?->attributesToArray() ?? [];
            $data = $this->validatedPriority($request, $priority);
            if ($priority) {
                $priority->update($data);
            } else {
                $priority = TicketPriority::query()->create($data);
            }
            $this->ensureSingleDefaultPriority($priority);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.priority.saved', $priority, $before, $priority->refresh()->attributesToArray());

            return $priority;
        });
    }

    public function deletePriority(Request $request, TicketPriority $priority): void
    {
        DB::transaction(function () use ($request, $priority) {
            $priority = TicketPriority::query()->lockForUpdate()->findOrFail($priority->id);
            if ($priority->tickets()->exists() || $this->ticketRuleReferences('set_priority', $priority->id)) {
                throw ValidationException::withMessages(['priority' => 'Ticket priority cannot be deleted while tickets or rules use it.']);
            }
            $before = $priority->attributesToArray();
            $priority->delete();
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.catalog.priority.deleted', $priority, $before, $priority->attributesToArray());
        });
    }

    public function validatedQueue(Request $request, ?TicketQueue $queue = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_queues,slug,'.($queue?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'email_address' => 'nullable|email|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketQueue::class, $data['slug'], $queue?->id, 'queue');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    public function validatedType(Request $request, ?TicketType $type = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_types,slug,'.($type?->id ?? 'NULL'),
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'is_deletable' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketType::class, $data['slug'], $type?->id, 'type');
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_deletable'] = $type?->is_system ? (bool) $type->is_deletable : (bool) ($data['is_deletable'] ?? true);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    public function validatedStatus(Request $request, ?TicketStatus $status = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_statuses,slug,'.($status?->id ?? 'NULL'),
            'state' => 'required|string|in:open,waiting,resolved,closed',
            'is_default' => 'nullable|boolean',
            'is_closed' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketStatus::class, $data['slug'], $status?->id, 'status');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_closed'] = (bool) ($data['is_closed'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    public function validatedPriority(Request $request, ?TicketPriority $priority = null): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:ticket_priorities,slug,'.($priority?->id ?? 'NULL'),
            'level' => 'required|integer|min:1|max:255',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ]);

        $data['slug'] = ($data['slug'] ?? null) ?: Str::slug($data['name']);
        $this->ensureUniqueSlug(TicketPriority::class, $data['slug'], $priority?->id, 'priority');
        $data['is_default'] = (bool) ($data['is_default'] ?? false);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);

        return $data;
    }

    public function ensureSingleDefaultQueue(?TicketQueue $defaultQueue = null): void
    {
        // Only a submitted default should clear competing defaults; ordinary edits must not unset them.
        if ($defaultQueue && ! $defaultQueue->is_default) {
            return;
        }

        $defaultQueue ??= TicketQueue::where('is_default', true)->orderBy('sort_order')->first();

        if (! $defaultQueue) {
            return;
        }

        TicketQueue::where('id', '!=', $defaultQueue->id)->update(['is_default' => false]);
    }

    public function ensureSingleDefaultStatus(?TicketStatus $defaultStatus = null): void
    {
        if ($defaultStatus && ! $defaultStatus->is_default) {
            return;
        }

        $defaultStatus ??= TicketStatus::where('is_default', true)->orderBy('sort_order')->first();

        if (! $defaultStatus) {
            return;
        }

        TicketStatus::where('id', '!=', $defaultStatus->id)->update(['is_default' => false]);
    }

    public function ensureSingleDefaultPriority(?TicketPriority $defaultPriority = null): void
    {
        if ($defaultPriority && ! $defaultPriority->is_default) {
            return;
        }

        $defaultPriority ??= TicketPriority::where('is_default', true)->orderBy('level')->first();

        if (! $defaultPriority) {
            return;
        }

        TicketPriority::where('id', '!=', $defaultPriority->id)->update(['is_default' => false]);
    }

    public function ensureUniqueSlug(string $modelClass, string $slug, ?int $ignoreId, string $field): void
    {
        $exists = $modelClass::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                $field => 'Slug is already in use.',
            ]);
        }
    }

    public function ticketRuleReferences(string $actionType, int $id): bool
    {
        return TicketRule::query()
            ->get()
            ->contains(function (TicketRule $rule) use ($actionType, $id) {
                foreach ((array) $rule->actions_json as $action) {
                    if (($action['type'] ?? '') === $actionType && (int) ($action['value'] ?? 0) === $id) {
                        return true;
                    }
                }

                return false;
            });
    }
}
