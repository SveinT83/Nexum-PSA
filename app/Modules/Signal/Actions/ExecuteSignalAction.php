<?php

namespace App\Modules\Signal\Actions;

use App\Models\Clients\Client;
use App\Modules\Contact\Models\Contact;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Jobs\DeliverSignalWebhook;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalWebhookDelivery;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ExecuteSignalAction
{
    public function handle(Signal $signal, SignalRule $rule, array $action): array
    {
        return match ($action['type'] ?? null) {
            'marketing_suppress_contact_email' => $this->suppressContact($signal),
            'tag_contact' => $this->tagModel($signal->contact, $action, 'contact'),
            'tag_client' => $this->tagModel($signal->client, $action, 'client'),
            'emit_signal' => $this->emitSignal($signal, $action),
            'sales_follow_up' => $this->createSalesFollowUp($signal, $rule, $action),
            'ticket_follow_up' => $this->createTicketFollowUp($signal, $rule, $action),
            'webhook' => $this->queueWebhook($signal, $rule, $action),
            default => ['type' => $action['type'] ?? 'unknown', 'status' => 'skipped', 'message' => 'Unknown signal action.'],
        };
    }

    private function suppressContact(Signal $signal): array
    {
        if (! $signal->contact) {
            return ['type' => 'marketing_suppress_contact_email', 'status' => 'skipped', 'message' => 'Signal has no contact.'];
        }

        $signal->contact->forceFill([
            'do_not_email' => true,
            'marketing_consent' => false,
        ])->save();

        return ['type' => 'marketing_suppress_contact_email', 'status' => 'done', 'contact_id' => $signal->contact_id];
    }

    private function tagModel(?Model $model, array $action, string $module): array
    {
        if (! $model || ! method_exists($model, 'tags')) {
            return ['type' => $action['type'] ?? 'tag', 'status' => 'skipped', 'message' => 'No taggable model.'];
        }

        $tagName = trim((string) ($action['tag'] ?? 'Signal'));
        $tag = Tag::query()->firstOrCreate(
            ['slug' => Str::slug($tagName)],
            ['name' => $tagName, 'active' => true],
        );

        $model->tags()->syncWithoutDetaching([
            $tag->id => ['module' => $module],
        ]);

        return ['type' => $action['type'], 'status' => 'done', 'tag_id' => $tag->id, 'tag' => $tag->name];
    }

    private function emitSignal(Signal $signal, array $action): array
    {
        $child = app(RecordSignal::class)->handle([
            'source_domain' => 'signal',
            'source_type' => $signal->getMorphClass(),
            'source_id' => $signal->id,
            'subject_type' => $signal->subject_type,
            'subject_id' => $signal->subject_id,
            'contact_id' => $signal->contact_id,
            'client_id' => $signal->client_id,
            'signal_type' => $action['signal_type'] ?? 'derived',
            'severity' => $action['severity'] ?? $signal->severity,
            'confidence' => $action['confidence'] ?? $signal->confidence,
            'summary' => $action['summary'] ?? 'Derived signal',
            'payload' => ['parent_signal_id' => $signal->id, 'action' => $action],
        ], processRules: false);

        return ['type' => 'emit_signal', 'status' => 'done', 'signal_id' => $child->id];
    }

    private function createSalesFollowUp(Signal $signal, SignalRule $rule, array $action): array
    {
        if (! $signal->client_id) {
            return ['type' => 'sales_follow_up', 'status' => 'skipped', 'message' => 'Signal has no client.'];
        }

        if (SalesActivity::query()->where('metadata->signal_id', $signal->id)->exists()) {
            return ['type' => 'sales_follow_up', 'status' => 'skipped', 'message' => 'Sales activity already exists for signal.'];
        }

        $opportunity = $this->findOpenSalesOpportunity($signal, $action);

        if (! $opportunity && ($action['create_if_missing'] ?? true)) {
            $opportunity = $this->createSalesOpportunity($signal, $rule, $action);
        }

        if (! $opportunity) {
            return ['type' => 'sales_follow_up', 'status' => 'skipped', 'message' => 'No open sales opportunity found.'];
        }

        $activity = SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => null,
            'type' => $action['activity_type'] ?? 'internal_note',
            'direction' => null,
            'subject' => $action['activity_subject'] ?? $this->salesFollowUpSubject($signal),
            'body' => $action['activity_body'] ?? $this->salesFollowUpBody($signal),
            'is_unread' => true,
            'read_at' => null,
            'metadata' => [
                'signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_type' => $signal->signal_type,
                'source_domain' => $signal->source_domain,
            ],
        ]);

        $updates = ['is_unread' => true];
        $minutes = (int) ($action['follow_up_minutes_from_now'] ?? 0);
        if ($minutes > 0) {
            $updates['next_follow_up_at'] = now()->addMinutes($minutes);
            $updates['next_follow_up_type'] = $action['next_follow_up_type'] ?? 'call';
            $updates['next_follow_up_note'] = $action['next_follow_up_note'] ?? $activity->subject;
        }

        $opportunity->forceFill($updates)->save();

        return [
            'type' => 'sales_follow_up',
            'status' => 'done',
            'opportunity_id' => $opportunity->id,
            'activity_id' => $activity->id,
        ];
    }

    private function findOpenSalesOpportunity(Signal $signal, array $action): ?SalesOpportunity
    {
        if (! ($action['append_to_existing'] ?? true)) {
            return null;
        }

        return SalesOpportunity::query()
            ->where('client_id', $signal->client_id)
            ->whereNotIn('status', ['won', 'lost', 'not_qualified', 'no_quote_allowed'])
            ->when($action['opportunity_type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->orderByDesc('is_unread')
            ->latest('updated_at')
            ->first();
    }

    private function createSalesOpportunity(Signal $signal, SignalRule $rule, array $action): SalesOpportunity
    {
        $probability = (int) ($action['probability_percent'] ?? 10);
        $estimatedValue = (float) ($action['estimated_value_ex_vat'] ?? 0);

        return SalesOpportunity::query()->create([
            'opportunity_key' => $this->nextSalesOpportunityKey(),
            'client_id' => $signal->client_id,
            'owner_id' => $action['owner_id'] ?? null,
            'title' => $action['opportunity_title'] ?? $this->salesFollowUpSubject($signal),
            'type' => $action['opportunity_type'] ?? 'upsell',
            'status' => $action['opportunity_status'] ?? 'new_lead',
            'summary' => $signal->summary,
            'needs' => $action['needs'] ?? null,
            'estimated_value_ex_vat' => $estimatedValue,
            'probability_percent' => $probability,
            'weighted_value_ex_vat' => round($estimatedValue * ($probability / 100), 2),
            'next_follow_up_at' => ! empty($action['follow_up_minutes_from_now'])
                ? now()->addMinutes((int) $action['follow_up_minutes_from_now'])
                : null,
            'next_follow_up_type' => $action['next_follow_up_type'] ?? null,
            'next_follow_up_note' => $action['next_follow_up_note'] ?? null,
            'is_unread' => true,
            'metadata' => [
                'created_from' => 'signal',
                'signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_type' => $signal->signal_type,
            ],
        ]);
    }

    private function salesFollowUpSubject(Signal $signal): string
    {
        return 'Signal follow-up: '.str_replace('_', ' ', $signal->signal_type);
    }

    private function salesFollowUpBody(Signal $signal): string
    {
        $lines = [
            $signal->summary ?: 'Signal requires sales follow-up.',
            '',
            'Source: '.$signal->source_domain,
            'Type: '.$signal->signal_type,
            'Confidence: '.$signal->confidence.'%',
        ];

        if (! empty($signal->payload['url'])) {
            $lines[] = 'URL: '.$signal->payload['url'];
        }

        return implode("\n", $lines);
    }

    private function nextSalesOpportunityKey(): string
    {
        do {
            $key = 'SO-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (SalesOpportunity::query()->where('opportunity_key', $key)->exists());

        return $key;
    }

    private function createTicketFollowUp(Signal $signal, SignalRule $rule, array $action): array
    {
        if ($ticket = Ticket::query()->where('metadata->signal_id', $signal->id)->first()) {
            return ['type' => 'ticket_follow_up', 'status' => 'skipped', 'message' => 'Ticket already exists for signal.', 'ticket_id' => $ticket->id];
        }

        $description = $action['description'] ?? $this->ticketFollowUpDescription($signal);
        $ticket = app(StoreTicket::class)->handle([
            'client_id' => $signal->client_id,
            'site_id' => $action['site_id'] ?? null,
            'contact_id' => $signal->contact?->clientUser?->id,
            'owner_id' => $action['owner_id'] ?? null,
            'queue_id' => $action['queue_id'] ?? null,
            'ticket_type_id' => $action['ticket_type_id'] ?? null,
            'priority_id' => $action['priority_id'] ?? null,
            'category_id' => $action['category_id'] ?? null,
            'channel' => 'signal',
            'subject' => $action['subject'] ?? $this->ticketFollowUpSubject($signal),
            'description' => $description,
            'impact' => $action['impact'] ?? null,
            'urgency' => $action['urgency'] ?? null,
        ]);

        $ticket->forceFill([
            'metadata' => array_merge($ticket->metadata ?? [], [
                'created_from' => 'signal',
                'signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_type' => $signal->signal_type,
                'source_domain' => $signal->source_domain,
            ]),
            'is_unread' => true,
        ])->save();

        return ['type' => 'ticket_follow_up', 'status' => 'done', 'ticket_id' => $ticket->id, 'ticket_key' => $ticket->ticket_key];
    }

    private function ticketFollowUpSubject(Signal $signal): string
    {
        return 'Signal follow-up: '.str_replace('_', ' ', $signal->signal_type);
    }

    private function ticketFollowUpDescription(Signal $signal): string
    {
        $lines = [
            $signal->summary ?: 'Signal requires ticket follow-up.',
            '',
            'Source: '.$signal->source_domain,
            'Type: '.$signal->signal_type,
            'Confidence: '.$signal->confidence.'%',
        ];

        if (! empty($signal->payload['url'])) {
            $lines[] = 'URL: '.$signal->payload['url'];
        }

        return implode("\n", $lines);
    }

    private function queueWebhook(Signal $signal, SignalRule $rule, array $action): array
    {
        $url = trim((string) ($action['url'] ?? ''));

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['type' => 'webhook', 'status' => 'skipped', 'message' => 'Invalid webhook URL.'];
        }

        $delivery = SignalWebhookDelivery::query()->create([
            'signal_id' => $signal->id,
            'signal_rule_id' => $rule->id,
            'url' => $url,
            'status' => 'pending',
            'payload' => [
                'signal' => $signal->toArray(),
                'rule' => ['id' => $rule->id, 'name' => $rule->name],
                'action' => $action,
            ],
        ]);

        DeliverSignalWebhook::dispatch($delivery->id);

        return ['type' => 'webhook', 'status' => 'queued', 'delivery_id' => $delivery->id];
    }
}
