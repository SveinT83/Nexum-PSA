<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->addStateColumns();
        $this->addTransitionColumns();

        // MySQL may use the legacy composite unique key as the supporting
        // index for this foreign key. Give the foreign key its own temporary
        // index before removing the uniqueness constraint.
        if (! Schema::hasIndex('ticket_workflow_states', 'workflow_state_workflow_fk_index')) {
            Schema::table('ticket_workflow_states', function (Blueprint $table): void {
                $table->index('ticket_workflow_id', 'workflow_state_workflow_fk_index');
            });
        }
        if (Schema::hasIndex('ticket_workflow_states', 'workflow_state_status_unique')) {
            Schema::table('ticket_workflow_states', function (Blueprint $table): void {
                $table->dropUnique('workflow_state_status_unique');
            });
        }

        if (! Schema::hasIndex('ticket_workflow_transitions', 'workflow_transition_workflow_fk_index')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->index('ticket_workflow_id', 'workflow_transition_workflow_fk_index');
            });
        }
        if (Schema::hasIndex('ticket_workflow_transitions', 'workflow_transition_unique')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->dropUnique('workflow_transition_unique');
            });
        }

        if (! Schema::hasTable('ticket_workflow_versions')) {
            Schema::create('ticket_workflow_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_workflow_id')->constrained('ticket_workflows')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->string('status')->default('published')->index();
                $table->json('definition');
                $table->foreignId('published_by')->nullable()->constrained('user_management')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamps();

                $table->unique(['ticket_workflow_id', 'version'], 'ticket_workflow_version_unique');
            });
        }

        $this->addWorkflowColumns();
        $this->addTicketColumns();

        if (! Schema::hasTable('ticket_workflow_histories')) {
            Schema::create('ticket_workflow_histories', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
                $table->foreignId('workflow_version_id')->nullable()->constrained('ticket_workflow_versions')->nullOnDelete();
                $table->string('event_type')->index();
                $table->string('from_state_key')->nullable();
                $table->string('to_state_key')->nullable();
                $table->string('transition_key')->nullable();
                $table->string('idempotency_key')->nullable()->unique();
                $table->json('requirements_snapshot')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->text('message')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['ticket_id', 'created_at']);
            });
        }

        $this->backfillStateKeysAndDefinitions();

        if (! Schema::hasIndex('ticket_workflow_states', 'workflow_state_key_unique')) {
            Schema::table('ticket_workflow_states', function (Blueprint $table): void {
                $table->unique(['ticket_workflow_id', 'state_key'], 'workflow_state_key_unique');
            });
        }
        if (! Schema::hasIndex('ticket_workflow_states', 'workflow_state_status_index')) {
            Schema::table('ticket_workflow_states', function (Blueprint $table): void {
                $table->index(['ticket_workflow_id', 'ticket_status_id'], 'workflow_state_status_index');
            });
        }

        if (! Schema::hasIndex('ticket_workflow_transitions', 'workflow_transition_key_unique')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->unique(['ticket_workflow_id', 'transition_key'], 'workflow_transition_key_unique');
            });
        }
        if (! Schema::hasIndex('ticket_workflow_transitions', 'workflow_transition_from_state_index')) {
            Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
                $table->index(['ticket_workflow_id', 'from_state_key'], 'workflow_transition_from_state_index');
            });
        }

        // The final workflow/state indexes now support the workflow foreign
        // keys, so the temporary compatibility indexes are no longer needed.
        if (Schema::hasIndex('ticket_workflow_states', 'workflow_state_workflow_fk_index')) {
            Schema::table('ticket_workflow_states', fn (Blueprint $table) => $table->dropIndex('workflow_state_workflow_fk_index'));
        }
        if (Schema::hasIndex('ticket_workflow_transitions', 'workflow_transition_workflow_fk_index')) {
            Schema::table('ticket_workflow_transitions', fn (Blueprint $table) => $table->dropIndex('workflow_transition_workflow_fk_index'));
        }
    }

    private function addStateColumns(): void
    {
        $columns = [
            'state_key' => fn (Blueprint $table) => $table->string('state_key')->nullable()->after('ticket_workflow_id'),
            'requirements' => fn (Blueprint $table) => $table->json('requirements')->nullable()->after('requires_knowledge_update'),
            'action_policy' => fn (Blueprint $table) => $table->json('action_policy')->nullable()->after('requirements'),
            'assignment_policy' => fn (Blueprint $table) => $table->json('assignment_policy')->nullable()->after('action_policy'),
            'commercial_policy' => fn (Blueprint $table) => $table->json('commercial_policy')->nullable()->after('assignment_policy'),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('ticket_workflow_states', $column)) {
                Schema::table('ticket_workflow_states', $definition);
            }
        }
    }

    private function addTransitionColumns(): void
    {
        $columns = [
            'transition_key' => fn (Blueprint $table) => $table->string('transition_key')->nullable()->after('ticket_workflow_id'),
            'from_state_key' => fn (Blueprint $table) => $table->string('from_state_key')->nullable()->after('transition_key'),
            'to_state_key' => fn (Blueprint $table) => $table->string('to_state_key')->nullable()->after('from_state_key'),
            'requirements' => fn (Blueprint $table) => $table->json('requirements')->nullable()->after('requires_knowledge_update'),
        ];

        foreach ($columns as $column => $definition) {
            if (! Schema::hasColumn('ticket_workflow_transitions', $column)) {
                Schema::table('ticket_workflow_transitions', $definition);
            }
        }
    }

    private function addWorkflowColumns(): void
    {
        if (! Schema::hasColumn('ticket_workflows', 'definition_status')) {
            Schema::table('ticket_workflows', fn (Blueprint $table) => $table->string('definition_status')->default('published')->after('description'));
        }
        if (! Schema::hasColumn('ticket_workflows', 'escalation_paths')) {
            Schema::table('ticket_workflows', fn (Blueprint $table) => $table->json('escalation_paths')->nullable()->after('definition_status'));
        }
        if (! Schema::hasColumn('ticket_workflows', 'published_version_id')) {
            Schema::table('ticket_workflows', function (Blueprint $table): void {
                $table->foreignId('published_version_id')
                    ->nullable()
                    ->after('escalation_paths')
                    ->constrained('ticket_workflow_versions')
                    ->nullOnDelete();
            });
        }
    }

    private function addTicketColumns(): void
    {
        if (! Schema::hasColumn('tickets', 'workflow_version_id')) {
            Schema::table('tickets', function (Blueprint $table): void {
                $table->foreignId('workflow_version_id')
                    ->nullable()
                    ->after('workflow_id')
                    ->constrained('ticket_workflow_versions')
                    ->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('tickets', 'workflow_state_key')) {
            Schema::table('tickets', fn (Blueprint $table) => $table->string('workflow_state_key')->nullable()->after('workflow_version_id')->index());
        }
        if (! Schema::hasColumn('tickets', 'close_outcome')) {
            Schema::table('tickets', fn (Blueprint $table) => $table->string('close_outcome')->nullable()->after('closed_at')->index());
        }
        if (! Schema::hasColumn('tickets', 'close_reason')) {
            Schema::table('tickets', fn (Blueprint $table) => $table->text('close_reason')->nullable()->after('close_outcome'));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_workflow_histories');

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['workflow_state_key']);
            $table->dropIndex(['close_outcome']);
            $table->dropConstrainedForeignId('workflow_version_id');
            $table->dropColumn(['workflow_state_key', 'close_outcome', 'close_reason']);
        });

        Schema::table('ticket_workflows', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_version_id');
            $table->dropColumn(['definition_status', 'escalation_paths']);
        });

        Schema::dropIfExists('ticket_workflow_versions');

        Schema::table('ticket_workflow_transitions', function (Blueprint $table): void {
            $table->dropUnique('workflow_transition_key_unique');
            $table->dropIndex('workflow_transition_from_state_index');
            $table->dropColumn(['transition_key', 'from_state_key', 'to_state_key', 'requirements']);
            $table->unique(['ticket_workflow_id', 'from_status_id', 'to_status_id'], 'workflow_transition_unique');
        });

        Schema::table('ticket_workflow_states', function (Blueprint $table): void {
            $table->dropUnique('workflow_state_key_unique');
            $table->dropIndex('workflow_state_status_index');
            $table->dropColumn(['state_key', 'requirements', 'action_policy', 'assignment_policy', 'commercial_policy']);
            $table->unique(['ticket_workflow_id', 'ticket_status_id'], 'workflow_state_status_unique');
        });
    }

    private function backfillStateKeysAndDefinitions(): void
    {
        $now = now();
        $stateKeyByWorkflowAndStatus = [];

        foreach (DB::table('ticket_workflow_states')->orderBy('id')->get() as $state) {
            $stateKey = Str::slug((string) $state->name) ?: 'state';
            $stateKey .= '-'.$state->id;
            $requirements = $this->legacyRequirements($state);

            DB::table('ticket_workflow_states')->where('id', $state->id)->update([
                'state_key' => $stateKey,
                'requirements' => json_encode($requirements),
                'action_policy' => json_encode([]),
                'assignment_policy' => json_encode([]),
                'commercial_policy' => json_encode([]),
                'updated_at' => $now,
            ]);

            $stateKeyByWorkflowAndStatus[$state->ticket_workflow_id][$state->ticket_status_id] = $stateKey;
        }

        foreach (DB::table('ticket_workflow_transitions')->orderBy('id')->get() as $transition) {
            $fromStateKey = $stateKeyByWorkflowAndStatus[$transition->ticket_workflow_id][$transition->from_status_id] ?? null;
            $toStateKey = $stateKeyByWorkflowAndStatus[$transition->ticket_workflow_id][$transition->to_status_id] ?? null;

            DB::table('ticket_workflow_transitions')->where('id', $transition->id)->update([
                'transition_key' => 'transition-'.$transition->id,
                'from_state_key' => $fromStateKey,
                'to_state_key' => $toStateKey,
                'requirements' => json_encode($this->legacyRequirements($transition)),
                'updated_at' => $now,
            ]);
        }

        foreach (DB::table('ticket_workflows')->orderBy('id')->get() as $workflow) {
            $definition = $this->definitionForWorkflow((int) $workflow->id);
            $versionId = DB::table('ticket_workflow_versions')
                ->where('ticket_workflow_id', $workflow->id)
                ->where('version', 1)
                ->value('id');

            if ($versionId) {
                DB::table('ticket_workflow_versions')->where('id', $versionId)->update([
                    'status' => 'published',
                    'definition' => json_encode($definition),
                    'published_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $versionId = DB::table('ticket_workflow_versions')->insertGetId([
                    'ticket_workflow_id' => $workflow->id,
                    'version' => 1,
                    'status' => 'published',
                    'definition' => json_encode($definition),
                    'published_by' => null,
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('ticket_workflows')->where('id', $workflow->id)->update([
                'published_version_id' => $versionId,
                'definition_status' => 'published',
                'escalation_paths' => json_encode([]),
                'updated_at' => $now,
            ]);

            DB::table('tickets')->where('workflow_id', $workflow->id)->orderBy('id')->get()
                ->each(function ($ticket) use ($versionId, $stateKeyByWorkflowAndStatus, $workflow, $now): void {
                    $stateKey = $stateKeyByWorkflowAndStatus[$workflow->id][$ticket->status_id] ?? null;

                    DB::table('tickets')->where('id', $ticket->id)->update([
                        'workflow_version_id' => $versionId,
                        'workflow_state_key' => $stateKey,
                        'updated_at' => $now,
                    ]);
                });
        }
    }

    private function definitionForWorkflow(int $workflowId): array
    {
        $states = DB::table('ticket_workflow_states')
            ->where('ticket_workflow_id', $workflowId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($state) => [
                'state_key' => $state->state_key,
                'ticket_status_id' => (int) $state->ticket_status_id,
                'name' => $state->name,
                'is_initial' => (bool) $state->is_initial,
                'is_terminal' => (bool) $state->is_terminal,
                'requirements' => json_decode((string) $state->requirements, true) ?: $this->legacyRequirements($state),
                'action_policy' => json_decode((string) $state->action_policy, true) ?: [],
                'assignment_policy' => json_decode((string) $state->assignment_policy, true) ?: [],
                'commercial_policy' => json_decode((string) $state->commercial_policy, true) ?: [],
                'sort_order' => (int) $state->sort_order,
            ])->values()->all();

        $transitions = DB::table('ticket_workflow_transitions')
            ->where('ticket_workflow_id', $workflowId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($transition) => [
                'transition_key' => $transition->transition_key,
                'from_state_key' => $transition->from_state_key,
                'to_state_key' => $transition->to_state_key,
                'label' => $transition->label,
                'manual_enabled' => (bool) $transition->manual_enabled,
                'trigger_actions' => json_decode((string) $transition->trigger_actions, true) ?: [],
                'requirements' => json_decode((string) $transition->requirements, true) ?: $this->legacyRequirements($transition),
                'sort_order' => (int) $transition->sort_order,
            ])->values()->all();

        return [
            'schema_version' => 1,
            'states' => $states,
            'transitions' => $transitions,
            'escalation_paths' => [],
        ];
    }

    private function legacyRequirements(object $record): array
    {
        $conditions = [];

        foreach ([
            'requires_note' => 'ticket.internal_note',
            'requires_response' => 'ticket.technician_response',
            'requires_resolution' => 'ticket.solution',
            'requires_knowledge_update' => 'ticket.knowledge_follow_up',
        ] as $column => $fact) {
            if ((bool) ($record->{$column} ?? false)) {
                $conditions[] = ['fact' => $fact, 'operator' => 'is_true', 'value' => null];
            }
        }

        return [
            'match' => 'all',
            'groups' => $conditions === [] ? [] : [[
                'match' => 'all',
                'conditions' => $conditions,
            ]],
        ];
    }
};
