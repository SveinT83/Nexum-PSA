<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldModelRegistry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketCustomFieldValueResolver;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Ticket-owned mutation boundary for normalized Custom Field values.
 */
final class SyncTicketCustomFieldValues
{
    private const CHANNELS = ['ui', 'api', 'ticket_rule'];

    public function __construct(
        private readonly SyncCustomFieldValues $sync,
        private readonly CustomFieldModelRegistry $models,
        private readonly TicketCustomFieldTargetValidator $targets,
        private readonly TicketCustomFieldValueResolver $values,
        private readonly TicketActionGuard $actionGuard,
        private readonly TicketMutationScopeGuard $scope,
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $context
     */
    public function handle(
        Ticket $ticket,
        array $input,
        User $actor,
        string $channel = 'ui',
        array $context = [],
    ): TicketMutationResult {
        $this->assertChannelAndGate($channel);
        $operation = (string) ($context['operation'] ?? 'update');
        if (! in_array($operation, ['create', 'update'], true)) {
            throw ValidationException::withMessages([
                'custom_fields' => 'The Ticket Custom Field operation is invalid.',
            ]);
        }

        return DB::transaction(function () use (
            $ticket,
            $input,
            $actor,
            $channel,
            $context,
            $operation,
        ): TicketMutationResult {
            $ticket = Ticket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->scope->assert($ticket);
            $this->authorizeTicket($ticket, $actor, $operation, $context);

            if ($input !== [] && array_is_list($input)) {
                throw ValidationException::withMessages([
                    'custom_fields' => 'Ticket Custom Field input must be keyed by field key.',
                ]);
            }

            $definitions = CustomFieldDefinition::query()
                ->whereIn('model_type', $this->models->storageTypesFor(Ticket::class))
                ->where('active', true)
                ->orderBy('id')
                ->get();
            $this->assertDefinitionAliasesAreUnambiguous($definitions);

            $requested = [];
            foreach ($input as $key => $value) {
                if (! is_string($key) || trim($key) === '') {
                    $this->invalidInput();
                }

                $definition = $definitions->firstWhere('key', $key);
                if (! $definition || ! $this->editableInChannel($definition, $channel)) {
                    $this->invalidInput($key);
                }

                $resolved = $this->targets->resolve(
                    $this->targets->targetFor($definition),
                    'edit',
                    $actor,
                );
                if (! $resolved['valid']) {
                    $this->invalidInput($key);
                }

                $requested[$key] = [
                    'definition' => $definition,
                    'value' => $value,
                ];
            }

            if (($context['require_complete'] ?? false) === true) {
                $this->assertRequiredFieldsPresent($definitions, $requested, $actor, $channel);
            }

            $changes = [];
            $syncInput = [];
            foreach ($requested as $key => $request) {
                /** @var CustomFieldDefinition $definition */
                $definition = $request['definition'];
                $before = $this->values->current($ticket, $definition);
                $after = $this->values->normalize($definition, $request['value']);
                if ($this->values->equivalent($before, $after)) {
                    continue;
                }

                $changes[(int) $definition->id] = [
                    'definition' => $definition,
                    'before' => $before,
                    'after' => $after,
                ];
                $syncInput[$key] = $request['value'];
            }

            if ($changes === []) {
                return TicketMutationResult::noChange($ticket);
            }

            $this->sync->handle($ticket, $syncInput, $actor, $channel);

            foreach ($changes as $change) {
                $actual = $this->values->current($ticket, $change['definition']);
                if (! $this->values->equivalent($actual, $change['after'])) {
                    throw ValidationException::withMessages([
                        'custom_fields' => 'Ticket Custom Field persistence verification failed.',
                    ]);
                }
            }

            $ticket->forceFill(['updated_by' => $actor->id])->touch();

            $beforeAudit = [];
            $afterAudit = [];
            $facts = [];
            $directions = [];
            $changedFields = [];
            foreach ($changes as $definitionId => $change) {
                /** @var CustomFieldDefinition $definition */
                $definition = $change['definition'];
                $fieldKey = 'custom_field.'.$definitionId;
                $beforeAudit[$fieldKey] = $this->values->auditProjection($definition, $change['before']);
                $afterAudit[$fieldKey] = $this->values->auditProjection($definition, $change['after']);
                $facts[(string) $definitionId] = $this->values->fact(
                    $definition,
                    $change['before'],
                    $change['after'],
                );
                $directions[(string) $definitionId] = $this->direction(
                    $change['before'],
                    $change['after'],
                );
                $changedFields[] = $fieldKey;
            }

            $history = TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'custom_fields_changed',
                'message' => 'Ticket Custom Fields updated.',
                'before' => $beforeAudit,
                'after' => $afterAudit,
            ]);

            $sourceChannel = (string) ($context['source_channel'] ?? $channel);
            $sourceAction = (string) ($context['source_action'] ?? 'SyncTicketCustomFieldValues');
            $definitionIds = array_keys($changes);
            sort($definitionIds, SORT_NUMERIC);
            $event = TicketRuleMutationEvent::make(
                ticketId: (int) $ticket->id,
                eventKey: TicketRuleTriggerRegistry::CUSTOM_FIELDS_CHANGED,
                changedFields: $changedFields,
                before: $beforeAudit,
                after: $afterAudit,
                safeFacts: [
                    'custom_fields' => $facts,
                    'changed_custom_field_definition_ids' => $definitionIds,
                    'custom_field_change_directions' => $directions,
                    'event_source_channel' => $sourceChannel,
                    'event_source_action' => $sourceAction,
                ],
                classification: [
                    'custom_field_definition_ids' => $definitionIds,
                    'custom_field_change_directions' => $directions,
                    'raw_values_persisted' => false,
                ],
                sourceChannel: $sourceChannel,
                sourceAction: $sourceAction,
                deliveryIdentity: (string) ($context['delivery_identity'] ?? 'ticket-event:'.$history->id),
                relatedRecordType: TicketEvent::class,
                relatedRecordId: (int) $history->id,
                correlationUuid: $context['correlation_uuid'] ?? null,
                causationUuid: $context['causation_uuid'] ?? null,
            );

            if (! (bool) ($context['_suppress_ticket_rule_dispatch']
                ?? $context['suppress_rule_dispatch']
                ?? false)) {
                $this->dispatchRules->handle($ticket, $event, $actor);
            }

            return new TicketMutationResult($ticket->refresh(), $event);
        });
    }

    /** @param array<string, mixed> $context */
    private function authorizeTicket(
        Ticket $ticket,
        User $actor,
        string $operation,
        array $context,
    ): void {
        if ($operation === 'create') {
            if (($context['require_complete'] ?? false) !== true
                || (! $actor->isActive() && ! $actor->isSystemActor())
                || ! Permission::query()
                    ->where('name', 'ticket.create')
                    ->where('guard_name', 'web')
                    ->exists()
                || ! $actor->can('ticket.create')) {
                throw ValidationException::withMessages([
                    'custom_fields' => 'Ticket creation does not authorize these Custom Field values.',
                ]);
            }

            return;
        }

        if ($reason = $this->actionGuard->reason($ticket, TicketAction::UPDATE_FIELDS, $actor)) {
            throw ValidationException::withMessages(['custom_fields' => $reason]);
        }
    }

    private function assertChannelAndGate(string $channel): void
    {
        if (! in_array($channel, self::CHANNELS, true)) {
            throw ValidationException::withMessages([
                'custom_fields' => 'The Ticket Custom Field channel is invalid.',
            ]);
        }

        $gate = match ($channel) {
            'ui' => 'ui_write',
            'api' => 'api_write',
            default => 'rule_action',
        };
        if (config('ticket_rules.capabilities.custom_fields.'.$gate, false) !== true) {
            throw ValidationException::withMessages([
                'custom_fields' => 'Ticket Custom Field '.$channel.' writes are disabled.',
            ]);
        }
    }

    private function editableInChannel(
        CustomFieldDefinition $definition,
        string $channel,
    ): bool {
        return match ($channel) {
            'ui' => (bool) $definition->editable_in_ui,
            'api' => (bool) $definition->editable_via_api,
            default => true,
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CustomFieldDefinition>  $definitions
     */
    private function assertDefinitionAliasesAreUnambiguous($definitions): void
    {
        if ($definitions->groupBy('key')->contains(fn ($group): bool => $group->count() > 1)) {
            throw ValidationException::withMessages([
                'custom_fields' => 'Ticket Custom Field definition aliases are ambiguous.',
            ]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CustomFieldDefinition>  $definitions
     * @param  array<string, array<string, mixed>>  $requested
     */
    private function assertRequiredFieldsPresent(
        $definitions,
        array $requested,
        User $actor,
        string $channel,
    ): void {
        foreach ($definitions->where('required', true) as $definition) {
            if (! $this->editableInChannel($definition, $channel)
                || ! $this->targets->resolve(
                    $this->targets->targetFor($definition),
                    'edit',
                    $actor,
                )['valid']) {
                throw ValidationException::withMessages([
                    'custom_fields' => 'A required Ticket Custom Field is unavailable.',
                ]);
            }

            if (! array_key_exists($definition->key, $requested)) {
                throw ValidationException::withMessages([
                    'custom_fields.'.$definition->key => $definition->label.' is required.',
                ]);
            }
        }
    }

    private function direction(mixed $before, mixed $after): string
    {
        if (! $this->values->present($before) && $this->values->present($after)) {
            return 'set';
        }
        if ($this->values->present($before) && ! $this->values->present($after)) {
            return 'cleared';
        }

        return 'changed';
    }

    private function invalidInput(?string $key = null): never
    {
        throw ValidationException::withMessages([
            $key === null ? 'custom_fields' : 'custom_fields.'.$key => 'The Ticket Custom Field is unavailable or unauthorized.',
        ]);
    }
}
