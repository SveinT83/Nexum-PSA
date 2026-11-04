<?php

namespace App\Modules\Ticket\Actions;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Only unprocessed direct time can be removed; the audit survives the removed source. */
final class DeleteTicketTimeEntry
{
    public const LIFETIME = 600;

    public function authorize(Ticket $ticket, User $actor): void
    {
        abort_unless($actor->isActive() && $actor->tokenCan('tickets.time.delete'), 403);
        foreach (['ticket.view', 'ticket.register_time', 'ticket.update'] as $permission) {
            abort_unless(app(EnforceTechRoutePermission::class)->allowsActor($actor, $permission), 403);
        }
        app(TicketMutationScopeGuard::class)->assert($ticket);
    }

    public function preview(Ticket $ticket, TicketTimeEntry $entry, User $actor): array
    {
        $this->authorize($ticket, $actor);
        abort_unless((int) $entry->ticket_id === (int) $ticket->id, 404);
        $blockers = $this->blockers($ticket, $entry, $actor);

        return [
            'ticket_id' => $ticket->id,
            'time_entry_id' => $entry->id,
            'entry' => $entry->attributesToArray(),
            'allowed' => $blockers === [],
            'blockers' => $blockers,
            'expires_in_seconds' => self::LIFETIME,
            'preview_token' => $blockers ? null : Crypt::encryptString(json_encode([
                'purpose' => 'ticket_time_deletion', 'actor_id' => $actor->id,
                'ticket_id' => $ticket->id, 'time_entry_id' => $entry->id,
                'fingerprint' => $this->fingerprint($entry),
                'expires_at' => now()->timestamp + self::LIFETIME,
            ], JSON_THROW_ON_ERROR)),
        ];
    }

    public function handle(Ticket $ticket, int $entryId, User $actor, array $input): TicketEvent
    {
        $this->authorize($ticket, $actor);
        // Read ownership before the transaction so waiting for Workday cannot pin an old
        // InnoDB repeatable-read snapshot before the worker's reference commits.
        $candidate = TicketTimeEntry::query()->where('ticket_id', $ticket->id)->find($entryId);

        // Sharing the billing mutex prevents a financial source being created during deletion.
        return app(EconomyMutex::class)->run(function () use ($ticket, $entryId, $actor, $input, $candidate) {
            // Workday takes worker -> time entry -> Ticket locks. Use that order after Economy.
            $users = array_values(array_unique(array_filter([$actor->id, $candidate?->user_id])));
            sort($users);
            $lockedUsers = User::query()->whereIn('id', $users)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $currentActor = $lockedUsers->get($actor->id);
            abort_unless($currentActor, 403);
            $currentActor->withAccessToken($actor->currentAccessToken());

            $entry = TicketTimeEntry::query()->where('ticket_id', $ticket->id)->lockForUpdate()->find($entryId);
            abort_if($entry && (int) $entry->user_id !== (int) $candidate?->user_id, 409, 'Time entry ownership changed. Request a new preview.');
            $currentTicket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            $this->authorize($currentTicket, $currentActor);
            $requestHash = hash('sha256', json_encode([
                'ticket_id' => (int) $ticket->id, 'time_entry_id' => $entryId,
                'reason' => $input['reason'], 'preview_token' => $input['preview_token'],
            ], JSON_THROW_ON_ERROR));
            $keyHash = hash('sha256', $input['idempotency_key']);
            // Actor row serialization makes this receipt unique without a new schema.
            $receipt = TicketEvent::query()->where('actor_id', $actor->id)
                ->where('type', 'time_entry_deleted')->where('metadata->key_hash', $keyHash)->first();
            if ($receipt) {
                abort_unless((int) $receipt->ticket_id === (int) $ticket->id
                    && hash_equals((string) data_get($receipt->metadata, 'request_hash'), $requestHash), 409,
                    'This idempotency key was already used for a different deletion.');
                $this->assertWorkflow($currentTicket, $currentActor);

                return $receipt;
            }

            abort_unless($entry, 404);
            $this->assertPreview($input['preview_token'], $currentTicket, $entry, $currentActor);
            $blockers = $this->blockers($currentTicket, $entry, $currentActor, lock: true);
            abort_if($blockers !== [], 409, 'Time entry cannot be deleted: '.implode(', ', $blockers).'.');
            $event = TicketEvent::create([
                'ticket_id' => $ticket->id, 'actor_id' => $actor->id,
                'type' => 'time_entry_deleted', 'message' => 'Erroneous Ticket time entry deleted.',
                'before' => $entry->attributesToArray(),
                'after' => ['deleted' => true, 'time_entry_id' => $entry->id, 'reason' => $input['reason']],
                'metadata' => ['key_hash' => $keyHash, 'request_hash' => $requestHash],
            ]);
            abort_unless($entry->delete(), 409, 'Time entry deletion was not applied.');
            // Exact retries must not invalidate unrelated Economy previews.
            DB::table('economy_write_mutex')->where('id', 1)->increment('revision');

            return $event->refresh();
        }, advance: false);
    }

    /** This is an exact authorized receipt, not unrestricted TicketEvent metadata. */
    public function receipt(Ticket $ticket, TicketEvent $event, User $actor): array
    {
        $this->authorize($ticket, $actor);
        $this->assertWorkflow($ticket, $actor);
        abort_unless((int) $event->ticket_id === (int) $ticket->id && $event->type === 'time_entry_deleted', 404);

        return [
            'deleted' => true, 'ticket_id' => $ticket->id,
            'time_entry_id' => (int) data_get($event->after, 'time_entry_id'),
            'audit_event_id' => $event->id, 'actor_id' => $event->actor_id,
            'reason' => data_get($event->after, 'reason'),
            'deleted_at' => $event->created_at?->toISOString(),
            'receipt_url' => route('api.v1.tickets.time-entry-deletions.show', [$ticket, $event]),
        ];
    }

    private function assertWorkflow(Ticket $ticket, User $actor): void
    {
        abort_if(app(TicketActionGuard::class)->reason($ticket, TicketAction::REGISTER_TIME, $actor) !== null,
            409, 'Time correction is blocked by the current Ticket workflow.');
    }

    /** Check every financial link, even if a source status was left pending by an old operation. */
    private function blockers(Ticket $ticket, TicketTimeEntry $entry, User $actor, bool $lock = false): array
    {
        $blockers = [];
        if (app(TicketActionGuard::class)->reason($ticket, TicketAction::REGISTER_TIME, $actor) !== null) {
            $blockers[] = 'workflow_blocked';
        }
        if ($entry->task_id !== null || $entry->type !== 'manual') {
            $blockers[] = 'not_direct_manual_time';
        }
        if ($entry->billing_status !== 'pending' || $entry->timebank_status !== 'pending') {
            $blockers[] = 'time_already_processed';
        }
        if ($entry->allocation()->when($lock, fn ($query) => $query->lockForUpdate())->exists()) {
            $blockers[] = 'financial_allocation_exists';
        }
        if (EconomyOrderLine::query()->whereIn('source_type', array_unique([$entry->getMorphClass(), TicketTimeEntry::class]))
            ->where('source_id', $entry->id)->when($lock, fn ($query) => $query->lockForUpdate())->exists()) {
            $blockers[] = 'financial_order_exists';
        }
        abort_unless(Schema::hasTable('workday_source_allocations') && Schema::hasTable('workday_revisions'),
            503, 'Apply the existing Workday source migrations before enabling time deletion.');
        $key = 'ticket:'.$entry->id;
        $referenced = DB::table('workday_source_allocations')->where('source_key', $key)->when($lock, fn ($query) => $query->lockForUpdate())->exists();
        // Also protect retained pre-index snapshots; do not limit to today's/current revision.
        if (! $referenced) {
            foreach (DB::table('workday_revisions')->where('snapshot', 'like', '%"'.$key.'"%')->select('snapshot')->when($lock, fn ($query) => $query->lockForUpdate())->cursor() as $revision) {
                $snapshot = json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR);
                foreach ($snapshot['allocations'] ?? [] as $allocation) {
                    if (($allocation['source_key'] ?? null) === $key) {
                        $referenced = true;
                        break 2;
                    }
                }
            }
        }
        if ($referenced) {
            $blockers[] = 'retained_workday_reference';
        }

        return $blockers;
    }

    private function assertPreview(string $token, Ticket $ticket, TicketTimeEntry $entry, User $actor): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['preview_token' => 'Request a valid deletion preview.']);
        }
        abort_unless(is_array($payload)
            && ($payload['purpose'] ?? null) === 'ticket_time_deletion'
            && ($payload['actor_id'] ?? null) === $actor->id
            && ($payload['ticket_id'] ?? null) === $ticket->id
            && ($payload['time_entry_id'] ?? null) === $entry->id
            && ($payload['expires_at'] ?? 0) > now()->timestamp
            && ($payload['fingerprint'] ?? null) === $this->fingerprint($entry),
            409, 'Deletion preview expired or the time entry changed. Request a new preview.');
    }

    private function fingerprint(TicketTimeEntry $entry): string
    {
        return hash('sha256', json_encode($entry->attributesToArray(), JSON_THROW_ON_ERROR));
    }
}
