<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use Spatie\Permission\Models\Permission;

final class TicketRuleCompatibilityActionExecutor
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly TicketRuleAuditSanitizer $sanitizer,
        private readonly TicketRuleCompatibilityTargetValidator $targetValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    public function handle(
        Ticket $ticket,
        array $action,
        User $automationActor,
        TicketRuleEventEnvelope $event,
        bool $apply,
    ): array {
        $type = (string) ($action['type'] ?? '');
        $targetFailure = $this->targetValidator->failureCode($action);
        if ($targetFailure !== null) {
            throw new TicketRuleActionFailure(
                str_ends_with($targetFailure, '_target_unavailable')
                    ? 'target_missing'
                    : $targetFailure,
                'The Ticket Rule action target is unavailable or invalid.',
            );
        }

        $requiredPermission = $type === 'emit_signal' ? 'signal.action.execute' : 'ticket.update';

        $this->assertPermission($automationActor, $requiredPermission);

        $guardAction = $type === 'set_sla'
            ? TicketAction::APPLY_SLA
            : TicketAction::UPDATE_FIELDS;
        $decision = $this->guard->decision($ticket, $guardAction, $automationActor);

        if (! ($decision['allowed'] ?? false)) {
            throw new TicketRuleActionFailure(
                'action_guard_denied',
                'The Ticket action policy denied this automation action.',
            );
        }

        $result = match ($type) {
            'set_ticket_type' => $this->setTicketType($ticket, $action, $apply, $automationActor),
            'set_queue' => $this->setField($ticket, $action, 'queue_id', TicketQueue::class, $apply, $automationActor),
            'set_priority' => $this->setField($ticket, $action, 'priority_id', TicketPriority::class, $apply, $automationActor),
            'set_sla' => $this->setField($ticket, $action, 'sla_id', Sla::class, $apply, $automationActor),
            'set_category' => $this->setCategory($ticket, $action, $apply, $automationActor),
            'add_tag' => $this->addTag($ticket, $action, $apply, $automationActor),
            'emit_signal' => $this->emitSignal($ticket, $action, $event),
            default => throw new TicketRuleActionFailure(
                'unsupported_compatibility_action',
                'The compatibility action type is not supported.',
            ),
        };

        return $result + [
            'authorization' => [
                'permission' => $requiredPermission,
                'ticket_action' => $guardAction,
                'allowed' => true,
            ],
            'derived_events' => [],
            'assignment_decision' => $type === 'set_queue',
            'sla_decision' => false,
        ];
    }

    /** @param array<string, mixed> $action */
    public function snapshot(array $action): array
    {
        return $this->sanitizer->map($action);
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()
            || ! $actor->can($permission)) {
            throw new TicketRuleActionFailure(
                'automation_permission_denied',
                'The Ticket Rule automation actor lacks a required permission.',
            );
        }
    }

    /** @param array<string, mixed> $action */
    private function setTicketType(Ticket $ticket, array $action, bool $apply, User $actor): array
    {
        $target = TicketType::query()->find($this->targetId($action));

        if (! $target) {
            throw new TicketRuleActionFailure('target_missing', 'The Ticket type target is unavailable.');
        }

        $before = [
            'ticket_type_id' => $ticket->ticket_type_id,
            'type' => $ticket->type,
        ];
        $after = [
            'ticket_type_id' => (int) $target->id,
            'type' => (string) $target->slug,
        ];

        if ($before === $after) {
            return $this->noChange();
        }

        if ($apply) {
            $ticket->forceFill($after + ['updated_by' => $actor->id])->save();
        }

        return $this->changed($before, $after);
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function setField(Ticket $ticket, array $action, string $field, string $model, bool $apply, User $actor): array
    {
        $targetId = $this->targetId($action);

        if (! $model::query()->whereKey($targetId)->exists()) {
            throw new TicketRuleActionFailure('target_missing', 'The Ticket Rule action target is unavailable.');
        }

        $before = [$field => $ticket->{$field} !== null ? (int) $ticket->{$field} : null];
        $after = [$field => $targetId];

        if ($before === $after) {
            return $this->noChange();
        }

        if ($apply) {
            $ticket->forceFill($after + ['updated_by' => $actor->id])->save();
        }

        return $this->changed($before, $after);
    }

    /** @param array<string, mixed> $action */
    private function setCategory(Ticket $ticket, array $action, bool $apply, User $actor): array
    {
        $targetId = $this->targetId($action);

        if (! Category::query()->forTickets()->active()->whereKey($targetId)->exists()) {
            throw new TicketRuleActionFailure('target_missing', 'The Ticket category target is unavailable.');
        }

        return $this->setKnownField($ticket, 'category_id', $targetId, $apply, $actor);
    }

    /** @param array<string, mixed> $action */
    private function addTag(Ticket $ticket, array $action, bool $apply, User $actor): array
    {
        $targetId = $this->targetId($action);

        if (! Tag::query()->where('active', true)->whereKey($targetId)->exists()) {
            throw new TicketRuleActionFailure('target_missing', 'The Ticket tag target is unavailable.');
        }

        $existing = $ticket->tags()->whereKey($targetId)->exists();

        if ($existing) {
            return $this->noChange();
        }

        if ($apply) {
            $ticket->tags()->syncWithoutDetaching([
                $targetId => ['module' => 'ticket'],
            ]);
            $ticket->forceFill(['updated_by' => $actor->id])->save();
        }

        return [
            'status' => 'succeeded',
            'changes' => [
                'tag_ids' => [
                    'before' => [],
                    'after' => [$targetId],
                ],
            ],
            'after_commit' => null,
        ];
    }

    /** @param array<string, mixed> $action */
    private function emitSignal(
        Ticket $ticket,
        array $action,
        TicketRuleEventEnvelope $event,
    ): array {
        if ($ticket->channel === 'signal' || $event->sourceChannel === 'signal') {
            return $this->noChange('signal_source_loop_suppressed');
        }

        $signalType = str((string) ($action['signal_type'] ?? $action['value'] ?? ''))
            ->trim()
            ->lower()
            ->replace([' ', '-'], '_')
            ->toString();

        if ($signalType === '') {
            throw new TicketRuleActionFailure('invalid_signal_type', 'Emit Signal requires a signal type.');
        }

        return [
            'status' => 'queued',
            'changes' => [],
            'after_commit' => [
                'type' => 'emit_signal',
                'signal_type' => $signalType,
                'severity' => $action['severity'] ?? 'info',
                'confidence' => max(0, min(100, (int) ($action['confidence'] ?? 100))),
                'summary' => $action['summary'] ?? null,
                'payload_note' => $action['payload_note'] ?? null,
            ],
        ];
    }

    private function setKnownField(Ticket $ticket, string $field, int $targetId, bool $apply, User $actor): array
    {
        $before = [$field => $ticket->{$field} !== null ? (int) $ticket->{$field} : null];
        $after = [$field => $targetId];

        if ($before === $after) {
            return $this->noChange();
        }

        if ($apply) {
            $ticket->forceFill($after + ['updated_by' => $actor->id])->save();
        }

        return $this->changed($before, $after);
    }

    /** @param array<string, mixed> $action */
    private function targetId(array $action): int
    {
        $value = $action['value'] ?? null;

        if (! is_numeric($value) || (int) $value < 1) {
            throw new TicketRuleActionFailure('invalid_target', 'The Ticket Rule action target is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after */
    private function changed(array $before, array $after): array
    {
        return [
            'status' => 'succeeded',
            'changes' => collect($after)
                ->mapWithKeys(fn (mixed $value, string $field): array => [
                    $field => [
                        'before' => $this->sanitizer->value($field, $before[$field] ?? null),
                        'after' => $this->sanitizer->value($field, $value),
                    ],
                ])
                ->all(),
            'after_commit' => null,
        ];
    }

    private function noChange(?string $reason = null): array
    {
        return [
            'status' => 'no_change',
            'changes' => [],
            'after_commit' => null,
            'reason_code' => $reason,
        ];
    }
}
