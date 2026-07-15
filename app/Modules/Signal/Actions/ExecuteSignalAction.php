<?php

namespace App\Modules\Signal\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Actions\CreateCustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Signal\Jobs\DeliverSignalWebhook;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalWebhookDelivery;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecuteSignalAction
{
    public function handle(Signal $signal, SignalRule $rule, array $action, int $actionIndex = 0): array
    {
        $idempotencyKey = $this->idempotencyKey($signal, $rule, $actionIndex);

        return match ($action['type'] ?? null) {
            'marketing_suppress_contact_email' => $this->suppressContact($signal),
            'tag_contact' => $this->tagModel($signal->contact, $action, 'contact'),
            'tag_client' => $this->tagModel($signal->client, $action, 'client'),
            'emit_signal' => $this->emitSignal($signal, $rule, $action, $idempotencyKey),
            'sales_follow_up' => $this->createSalesFollowUp($signal, $rule, $action, $idempotencyKey),
            'ticket_follow_up' => $this->createTicketFollowUp($signal, $rule, $action, $idempotencyKey),
            'task_follow_up' => $this->createTaskFollowUp($signal, $rule, $action, $idempotencyKey),
            'portal_invitation' => $this->createPortalInvitation($signal, $rule, $action, $idempotencyKey),
            'webhook' => $this->queueWebhook($signal, $rule, $action, $idempotencyKey),
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

    private function emitSignal(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        $existing = Signal::query()
            ->where('source_domain', 'signal')
            ->where('source_type', $signal->getMorphClass())
            ->where('source_id', $signal->id)
            ->where('payload->signal_action_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['type' => 'emit_signal', 'status' => 'skipped', 'message' => 'Derived signal already exists for this action.', 'signal_id' => $existing->id];
        }

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
            'payload' => [
                'parent_signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_action_key' => $idempotencyKey,
                'action' => $action,
            ],
        ], processRules: false);

        return ['type' => 'emit_signal', 'status' => 'done', 'signal_id' => $child->id];
    }

    private function createSalesFollowUp(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        if (! $signal->client_id) {
            return ['type' => 'sales_follow_up', 'status' => 'skipped', 'message' => 'Signal has no client.'];
        }

        if (SalesActivity::query()
            ->where('metadata->signal_id', $signal->id)
            ->where(function ($query) use ($rule, $idempotencyKey): void {
                $query->where('metadata->signal_action_key', $idempotencyKey)
                    ->orWhere(function ($legacy) use ($rule): void {
                        $legacy->whereNull('metadata->signal_action_key')->where('metadata->signal_rule_id', $rule->id);
                    });
            })
            ->exists()) {
            return ['type' => 'sales_follow_up', 'status' => 'skipped', 'message' => 'Sales activity already exists for signal.'];
        }

        $opportunity = $this->findOpenSalesOpportunity($signal, $action);

        if (! $opportunity && ($action['create_if_missing'] ?? true)) {
            $opportunity = $this->createSalesOpportunity($signal, $rule, $action, $idempotencyKey);
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
                'signal_action_key' => $idempotencyKey,
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

    private function createSalesOpportunity(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): SalesOpportunity
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
                'signal_action_key' => $idempotencyKey,
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

    private function createTicketFollowUp(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        if ($ticket = Ticket::query()
            ->where('metadata->signal_id', $signal->id)
            ->where(function ($query) use ($rule, $idempotencyKey): void {
                $query->where('metadata->signal_action_key', $idempotencyKey)
                    ->orWhere(function ($legacy) use ($rule): void {
                        $legacy->whereNull('metadata->signal_action_key')->where('metadata->signal_rule_id', $rule->id);
                    });
            })
            ->first()) {
            return ['type' => 'ticket_follow_up', 'status' => 'skipped', 'message' => 'Ticket already exists for signal.', 'ticket_id' => $ticket->id];
        }

        $description = $action['description'] ?? $this->ticketFollowUpDescription($signal);
        $ticket = app(StoreTicket::class)->handle([
            'client_id' => $signal->client_id,
            'site_id' => $action['site_id'] ?? data_get($signal->payload, 'matched_site_id'),
            'contact_id' => $action['contact_id'] ?? data_get($signal->payload, 'matched_client_user_id') ?? $signal->contact?->clientUser?->id,
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
                'signal_action_key' => $idempotencyKey,
                'signal_type' => $signal->signal_type,
                'source_domain' => $signal->source_domain,
            ]),
            'is_unread' => true,
        ])->save();

        return ['type' => 'ticket_follow_up', 'status' => 'done', 'ticket_id' => $ticket->id, 'ticket_key' => $ticket->ticket_key];
    }

    private function createTaskFollowUp(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        $existing = Task::query()
            ->where('source_type', 'signal')
            ->where('source_id', $signal->id)
            ->where('metadata->signal_rule_id', $rule->id)
            ->where(function ($query) use ($idempotencyKey): void {
                $query->where('metadata->signal_action_key', $idempotencyKey)
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('metadata->signal_action_key')->where('metadata->signal_action_type', 'task_follow_up');
                    });
            })
            ->first();

        if ($existing) {
            return ['type' => 'task_follow_up', 'status' => 'skipped', 'message' => 'Task already exists for signal rule.', 'task_id' => $existing->id];
        }

        $actor = $this->resolveActor($rule, $action);

        if (! $actor) {
            return ['type' => 'task_follow_up', 'status' => 'skipped', 'message' => 'Signal rule has no actor user for task creation.'];
        }

        $dueMinutes = (int) ($action['due_minutes_from_now'] ?? 0);
        $task = app(StoreTask::class)->handle([
            'title' => $action['title'] ?? $action['subject'] ?? $this->taskFollowUpSubject($signal),
            'description' => $action['description'] ?? $this->taskFollowUpDescription($signal),
            'client_id' => $signal->client_id,
            'site_id' => $action['site_id'] ?? data_get($signal->payload, 'matched_site_id'),
            'queue_id' => $action['queue_id'] ?? null,
            'priority_id' => $action['priority_id'] ?? null,
            'category_id' => $action['category_id'] ?? null,
            'assigned_to' => $action['assigned_to'] ?? $action['owner_id'] ?? null,
            'due_at' => $dueMinutes > 0 ? now()->addMinutes($dueMinutes) : null,
            'estimated_minutes' => $action['estimated_minutes'] ?? null,
            'source_type' => 'signal',
            'source_id' => $signal->id,
            'metadata' => [
                'created_from' => 'signal',
                'signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_action_key' => $idempotencyKey,
                'signal_action_type' => 'task_follow_up',
                'signal_type' => $signal->signal_type,
                'source_domain' => $signal->source_domain,
                'intake_submission_id' => data_get($signal->payload, 'intake_submission_id'),
            ],
        ], $actor, $signal->client ?: $actor);

        return ['type' => 'task_follow_up', 'status' => 'done', 'task_id' => $task->id];
    }

    private function createPortalInvitation(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        if (! $signal->client_id || ! $signal->contact_id) {
            return ['type' => 'portal_invitation', 'status' => 'skipped', 'message' => 'Signal has no matched client and contact.'];
        }

        $actor = $this->resolveActor($rule, $action);

        if (! $actor) {
            return ['type' => 'portal_invitation', 'status' => 'skipped', 'message' => 'Signal rule has no actor user for portal invitation.'];
        }

        $role = (string) ($action['role'] ?? CustomerPortalMembership::ROLE_VIEWER);

        if (! array_key_exists($role, CustomerPortalMembership::roleOptions())) {
            return ['type' => 'portal_invitation', 'status' => 'skipped', 'message' => 'Invalid portal role.'];
        }

        $site = $this->portalInvitationSite($signal, $action);

        if (($action['site_id'] ?? data_get($signal->payload, 'matched_site_id')) && ! $site) {
            return ['type' => 'portal_invitation', 'status' => 'skipped', 'message' => 'Matched site does not belong to signal client.'];
        }

        $existing = CustomerPortalInvitation::query()
            ->where('metadata->signal_id', $signal->id)
            ->where('metadata->signal_rule_id', $rule->id)
            ->where(function ($query) use ($idempotencyKey): void {
                $query->where('metadata->signal_action_key', $idempotencyKey)
                    ->orWhereNull('metadata->signal_action_key');
            })
            ->whereNull('revoked_at')
            ->first();

        if ($existing) {
            return ['type' => 'portal_invitation', 'status' => 'skipped', 'message' => 'Portal invitation already exists for signal rule.', 'invitation_id' => $existing->id];
        }

        try {
            $invitation = app(CreateCustomerPortalInvitation::class)->handle(
                $actor,
                $signal->contact,
                $signal->client,
                $site,
                $role,
                blank($action['email'] ?? null) ? null : (string) $action['email'],
                'signal_automation',
            );
        } catch (ValidationException $exception) {
            return [
                'type' => 'portal_invitation',
                'status' => 'skipped',
                'message' => collect($exception->errors())->flatten()->first() ?: 'Portal invitation validation failed.',
            ];
        }

        $invitation->forceFill([
            'metadata' => array_merge($invitation->metadata ?? [], [
                'created_from' => 'signal_automation',
                'signal_id' => $signal->id,
                'signal_rule_id' => $rule->id,
                'signal_action_key' => $idempotencyKey,
                'signal_type' => $signal->signal_type,
                'source_domain' => $signal->source_domain,
                'intake_submission_id' => data_get($signal->payload, 'intake_submission_id'),
            ]),
        ])->save();

        return ['type' => 'portal_invitation', 'status' => 'done', 'invitation_id' => $invitation->id];
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

    private function taskFollowUpSubject(Signal $signal): string
    {
        return 'Signal task: '.str_replace('_', ' ', $signal->signal_type);
    }

    private function taskFollowUpDescription(Signal $signal): string
    {
        $lines = [
            $signal->summary ?: 'Signal requires task follow-up.',
            '',
            'Source: '.$signal->source_domain,
            'Type: '.$signal->signal_type,
            'Confidence: '.$signal->confidence.'%',
        ];

        if (! empty($signal->payload['intake_form_name'])) {
            $lines[] = 'Intake form: '.$signal->payload['intake_form_name'];
        }

        return implode("\n", $lines);
    }

    private function resolveActor(SignalRule $rule, array $action): ?User
    {
        $actorId = (int) ($action['actor_id'] ?? $action['creator_id'] ?? $rule->updated_by ?? $rule->created_by ?? 0);

        return $actorId > 0 ? User::query()->find($actorId) : null;
    }

    private function portalInvitationSite(Signal $signal, array $action): ?ClientSite
    {
        $siteId = (int) ($action['site_id'] ?? data_get($signal->payload, 'matched_site_id') ?? 0);

        if ($siteId <= 0) {
            return null;
        }

        return ClientSite::query()
            ->where('client_id', $signal->client_id)
            ->find($siteId);
    }

    private function queueWebhook(Signal $signal, SignalRule $rule, array $action, string $idempotencyKey): array
    {
        $url = trim((string) ($action['url'] ?? ''));

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return ['type' => 'webhook', 'status' => 'skipped', 'message' => 'Invalid webhook URL.'];
        }

        $existing = SignalWebhookDelivery::query()
            ->where('signal_id', $signal->id)
            ->where('signal_rule_id', $rule->id)
            ->where('payload->signal_action_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return ['type' => 'webhook', 'status' => 'skipped', 'message' => 'Webhook delivery already exists for this action.', 'delivery_id' => $existing->id];
        }

        $delivery = SignalWebhookDelivery::query()->create([
            'signal_id' => $signal->id,
            'signal_rule_id' => $rule->id,
            'url' => $url,
            'status' => 'pending',
            'payload' => [
                'signal_action_key' => $idempotencyKey,
                'signal' => $signal->toArray(),
                'rule' => ['id' => $rule->id, 'name' => $rule->name],
                'action' => $action,
            ],
        ]);

        DeliverSignalWebhook::dispatch($delivery->id);

        return ['type' => 'webhook', 'status' => 'queued', 'delivery_id' => $delivery->id];
    }

    private function idempotencyKey(Signal $signal, SignalRule $rule, int $actionIndex): string
    {
        return "signal:{$signal->id}:rule:{$rule->id}:action:{$actionIndex}";
    }
}
