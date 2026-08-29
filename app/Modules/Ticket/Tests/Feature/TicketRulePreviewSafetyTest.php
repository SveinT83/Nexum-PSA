<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRulePreviewService;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use ArrayObject;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRulePreviewSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set(
            'ticket_rules.capabilities.triggers',
            array_fill_keys(
                array_keys(app(TicketRuleTriggerRegistry::class)->definitions()),
                true,
            ),
        );
        config()->set(
            'ticket_rules.capabilities.actions',
            array_fill_keys(
                array_keys(app(TicketRuleActionProviderRegistry::class)->definitions()),
                true,
            ),
        );
        config()->set('ticket_rules.capabilities.custom_fields.rule_action', true);
        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', true);
    }

    #[Test]
    public function published_queue_preview_fails_closed_before_restricted_custom_field_evidence(): void
    {
        Permission::findOrCreate('ticket.update', 'web');
        $privateFieldKey = 'restricted_preview_fact';
        $privateFieldLabel = 'Restricted Preview Fact';
        $privateExpectedValue = 'private-preview-value-'.Str::uuid();
        $field = $this->customField(
            $privateFieldKey,
            $privateFieldLabel,
            'ticket.update',
            'ticket.update',
        );
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($field);
        $this->publish([
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => TicketCustomFieldTargetValidator::CURRENT,
                        'target' => $target,
                        'operator' => 'equals',
                        'value' => $privateExpectedValue,
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 5]],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Restricted Custom Field preview rule');
        $this->synchronizeFence();

        $ticket = $this->ticket('Restricted queue preview');
        $operator = $this->operator();
        $before = $this->writeSnapshot();
        $writeQueries = $this->captureWriteQueries();
        $failure = null;

        try {
            app(TicketRulePreviewService::class)->created(
                $ticket,
                $this->context($ticket),
                $operator,
            );
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame(
            'The published Ticket Rule queue preview is unavailable for this account.',
            $failure->getMessage(),
        );
        $this->assertStringNotContainsString($privateFieldKey, $failure->getMessage());
        $this->assertStringNotContainsString($privateFieldLabel, $failure->getMessage());
        $this->assertStringNotContainsString($privateExpectedValue, $failure->getMessage());
        $this->assertSame(
            [],
            $writeQueries->getArrayCopy(),
            'Rejected queue preview issued a database write query.',
        );
        $this->assertSame($before, $this->writeSnapshot());
    }

    #[Test]
    public function draft_preview_executes_write_capable_providers_without_any_write_query_or_side_effect(): void
    {
        Notification::fake();
        Queue::fake();
        Permission::findOrCreate('ticket.update', 'web');

        $field = $this->customField(
            'draft_preview_probe',
            'Draft Preview Probe',
            'ticket.update',
            'ticket.update',
        );
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($field);
        $tag = Tag::query()->create([
            'name' => 'Draft preview probe',
            'slug' => 'draft-preview-probe',
            'active' => true,
        ]);
        $ticket = $this->ticket('Draft no-write proof');
        $operator = $this->operator(['ticket.update']);
        $privateNote = 'Private draft note '.Str::uuid();
        $privateCustomFieldValue = 'Private draft field '.Str::uuid();
        $privateSignalSummary = 'Private draft signal '.Str::uuid();
        $privatePauseReason = 'Private draft pause '.Str::uuid();
        $actionTypes = [
            TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
            TicketRuleActionProviderRegistry::ADD_TAGS,
            TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
            TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE,
            TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT,
            TicketRuleActionProviderRegistry::PAUSE_WORKFLOW_AUTOMATION,
            TicketRuleActionProviderRegistry::EMIT_SIGNAL,
        ];
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => ['mode' => 'always', 'match' => 'ALL', 'groups' => []],
            'then_actions' => [
                [
                    'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                    'input' => ['fields' => ['impact' => 5]],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::ADD_TAGS,
                    'input' => ['tag_ids' => [$tag->id]],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                    'input' => ['target' => $target, 'value' => $privateCustomFieldValue],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE,
                    'input' => ['body' => $privateNote],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::RERUN_ASSIGNMENT,
                    'input' => [],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::PAUSE_WORKFLOW_AUTOMATION,
                    'input' => ['reason' => $privatePauseReason],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::EMIT_SIGNAL,
                    'input' => [
                        'signal_type' => 'preview_no_write_probe',
                        'severity' => 'warning',
                        'confidence' => 91,
                        'summary' => $privateSignalSummary,
                        'payload_note' => 'No delivery may leave draft preview.',
                    ],
                ],
            ],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 100],
        ];
        $before = $this->writeSnapshot();
        $writeQueries = $this->captureWriteQueries();

        $result = app(TicketRulePreviewService::class)->draft(
            $ticket,
            $definition,
            $operator,
        );

        $this->assertSame('draft_preview', $result['mode']);
        $this->assertSame('would_change', $result['terminal_status']);
        $this->assertTrue($result['conditions_matched']);
        $this->assertSame(
            $actionTypes,
            collect($result['actions'])->pluck('action.type')->all(),
        );
        foreach ($result['actions'] as $action) {
            $this->assertContains($action['status'], ['planned', 'no_change']);
        }
        $this->assertSame(
            'emit_signal',
            $result['actions'][array_key_last($result['actions'])]['after_commit_type'],
        );
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($privateNote, $encoded);
        $this->assertStringNotContainsString($privateCustomFieldValue, $encoded);
        $this->assertStringNotContainsString($privateSignalSummary, $encoded);
        $this->assertStringNotContainsString($privatePauseReason, $encoded);
        $this->assertSame(
            [],
            $writeQueries->getArrayCopy(),
            'Draft preview issued a database write query.',
        );
        $this->assertSame($before, $this->writeSnapshot());
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    /** @param list<string> $extraPermissions */
    private function operator(array $extraPermissions = []): User
    {
        $permissions = array_values(array_unique(array_merge([
            'ticket.view',
            'ticket.rule_preview',
        ], $extraPermissions)));
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo($permissions);

        return $operator->refresh();
    }

    private function ticket(string $subject): Ticket
    {
        return app(StoreTicket::class)->handle([
            'subject' => $subject,
            'description' => 'Ticket Rule preview safety fixture.',
            'channel' => 'email',
            'owner_id' => null,
            '_source_action' => __METHOD__,
        ]);
    }

    /** @return array<string, mixed> */
    private function context(Ticket $ticket): array
    {
        return [
            'channel' => $ticket->channel,
            'subject' => $ticket->subject,
            'description' => $ticket->description,
            '_source_action' => 'TicketRulePreviewSafetyTest',
        ];
    }

    private function customField(
        string $key,
        string $label,
        ?string $viewPermission,
        ?string $editPermission,
    ): CustomFieldDefinition {
        return CustomFieldDefinition::query()->create([
            'model_type' => Ticket::class,
            'key' => $key,
            'label' => $label,
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'view_permission' => $viewPermission,
            'edit_permission' => $editPermission,
            'active' => true,
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function publish(array $definition, string $name): TicketRuleVersion
    {
        $validated = app(TicketRulePublishedDefinitionValidator::class)
            ->validateForPublication($definition);
        $this->assertSame(
            TicketRulePublishedDefinitionValidator::STATUS_VALID,
            $validated['status'],
            (string) ($validated['reason_code'] ?? 'definition invalid'),
        );
        $definition = $validated['definition'];
        $checksum = $validated['checksum'];
        $weight = (int) data_get($definition, 'order.weight');

        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Published queue preview confidentiality fixture.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => $definition['trigger'],
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => $rule->description,
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_key' => 'preview-safety-'.$rule->id,
            'provenance_recorded_at' => now(),
        ]);
        $rule->forceFill([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_reason_code' => null,
            'compatibility_checked_at' => now(),
        ])->save();

        return $version->refresh();
    }

    private function synchronizeFence(): void
    {
        TicketRuleAuthorityFence::query()
            ->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['catalog_checksum' => app(TicketRuleCatalogFingerprint::class)->checksum()]);
    }

    /** @return ArrayObject<int, string> */
    private function captureWriteQueries(): ArrayObject
    {
        $writeQueries = new ArrayObject;
        DB::listen(static function (QueryExecuted $query) use ($writeQueries): void {
            if (preg_match('/^\s*(?:insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query->sql) === 1) {
                $writeQueries->append($query->sql);
            }
        });

        return $writeQueries;
    }

    /** @return array<string, list<string>> */
    private function writeSnapshot(): array
    {
        $tables = collect(Schema::getTableListing())
            ->filter(fn (string $table): bool => str_starts_with($table, 'ticket_')
                || in_array($table, [
                    'tickets',
                    'taggables',
                    'custom_field_values',
                    'signals',
                    'notifications',
                    'jobs',
                ], true))
            ->sort()
            ->values();

        return $tables->mapWithKeys(function (string $table): array {
            $rows = DB::table($table)
                ->get()
                ->map(fn (object $row): string => TicketRuleStableJson::checksum((array) $row))
                ->sort()
                ->values()
                ->all();

            return [$table => $rows];
        })->all();
    }
}
