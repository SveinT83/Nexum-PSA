<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MarkTicketAsNotTicket
{
    public function handle(Ticket $ticket, ?User $actor = null): int
    {
        return DB::transaction(function () use ($ticket, $actor) {
            $emails = $this->linkedEmails($ticket);

            if ($emails->isEmpty()) {
                throw new InvalidArgumentException('This ticket has no linked inbound email to return to Inbox.');
            }

            $tag = $this->notTicketTag();

            foreach ($emails as $email) {
                if (! $email->tags()->where('tags.id', $tag->id)->exists()) {
                    $email->tags()->attach($tag->id, ['module' => 'email']);
                }

                $email->forceFill([
                    'ticket_id' => null,
                    'state' => 'untriaged',
                ])->save();

                $this->upsertNotTicketRule($email, $actor);
            }

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'marked_not_ticket',
                'message' => 'Ticket returned to Inbox as not ticket.',
                'after' => [
                    'email_message_ids' => $emails->pluck('id')->values()->all(),
                    'tag' => 'not-ticket',
                ],
            ]);

            $metadata = $ticket->metadata ?? [];
            $metadata['not_ticket'] = [
                'by_user_id' => $actor?->id,
                'at' => now()->toIso8601String(),
                'email_message_ids' => $emails->pluck('id')->values()->all(),
            ];

            $ticket->forceFill([
                'metadata' => $metadata,
                'updated_by' => $actor?->id,
            ])->save();

            $ticket->delete();

            return $emails->count();
        });
    }

    private function linkedEmails(Ticket $ticket): Collection
    {
        $messageEmailIds = $ticket->messages()
            ->get(['metadata'])
            ->pluck('metadata')
            ->map(fn ($metadata) => (int) ($metadata['email_message_id'] ?? 0))
            ->filter()
            ->values();

        return EmailMessage::query()
            ->where('ticket_id', $ticket->id)
            ->when($messageEmailIds->isNotEmpty(), function ($query) use ($messageEmailIds) {
                $query->orWhereIn('id', $messageEmailIds);
            })
            ->get()
            ->unique('id')
            ->values();
    }

    private function notTicketTag(): Tag
    {
        return Tag::firstOrCreate(
            ['name' => 'not-ticket'],
            [
                'slug' => 'not-ticket',
                'color' => '#6c757d',
                'active' => true,
            ]
        );
    }

    private function upsertNotTicketRule(EmailMessage $email, ?User $actor): void
    {
        $conditions = [
            ['field' => 'from', 'operator' => 'equals', 'value' => (string) $email->from_email],
            ['field' => 'subject', 'operator' => 'equals', 'value' => (string) $email->subject],
        ];
        $actions = [
            ['type' => 'tag', 'value' => 'not-ticket'],
        ];

        $rule = EmailRule::query()
            ->where('trigger', EmailRule::TRIGGER_INBOUND)
            ->get()
            ->first(fn (EmailRule $rule) => ($rule->conditions_json ?? []) === $conditions);

        $payload = [
            'name' => 'Not ticket: '.Str::limit((string) ($email->subject ?: $email->from_email ?: 'Inbound email'), 80, ''),
            'description' => 'Created from Ticket when a technician marked an inbound email as not ticket.',
            'trigger' => EmailRule::TRIGGER_INBOUND,
            'weight' => 0,
            'is_active' => true,
            'stop_processing' => true,
            'conditions_json' => $conditions,
            'actions_json' => $actions,
            'updated_by' => $actor?->id,
        ];

        if ($rule) {
            $rule->forceFill($payload)->save();
            return;
        }

        EmailRule::create($payload + [
            'created_by' => $actor?->id,
            'hit_count' => 0,
        ]);
    }
}
