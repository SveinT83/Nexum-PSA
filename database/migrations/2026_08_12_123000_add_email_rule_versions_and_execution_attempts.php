<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_rules')) {
            return;
        }

        Schema::table('email_rules', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_rules', 'lifecycle_status')) {
                $table->string('lifecycle_status')->default('published')->after('is_active')->index();
            }
            if (! Schema::hasColumn('email_rules', 'published_version_id')) {
                $table->unsignedBigInteger('published_version_id')->nullable()->after('lifecycle_status')->index();
            }
            if (! Schema::hasColumn('email_rules', 'published_by')) {
                $table->unsignedBigInteger('published_by')->nullable()->after('updated_by')->index();
            }
            if (! Schema::hasColumn('email_rules', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('published_by');
            }
        });

        if (! Schema::hasTable('email_rule_versions')) {
            Schema::create('email_rule_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('email_rule_id')->constrained('email_rules')->cascadeOnDelete();
                $table->unsignedInteger('version_number');
                $table->string('status')->default('published')->index();
                $table->unsignedBigInteger('published_by')->nullable()->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->string('trigger')->default('on_inbound')->index();
                $table->string('routing_phase')->default('normal')->index();
                $table->unsignedInteger('weight')->default(10);
                $table->boolean('is_active')->default(true);
                $table->boolean('stop_processing')->default(false);
                $table->json('conditions_json');
                $table->json('actions_json');
                $table->json('account_ids_json');
                $table->string('snapshot_hash', 64)->index();
                $table->timestamps();

                $table->unique(['email_rule_id', 'version_number'], 'email_rule_versions_rule_number_unique');
                $table->index(['email_rule_id', 'status'], 'email_rule_versions_rule_status_index');
            });
        }

        if (! Schema::hasTable('email_rule_execution_attempts')) {
            Schema::create('email_rule_execution_attempts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('email_rule_id')->nullable()->constrained('email_rules')->nullOnDelete();
                $table->foreignId('email_rule_version_id')->nullable()->constrained('email_rule_versions')->nullOnDelete();
                $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
                $table->foreignId('email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
                $table->string('routing_phase')->default('normal')->index();
                $table->string('status')->default('running')->index();
                $table->string('reason_code')->nullable()->index();
                $table->string('idempotency_key', 80)->unique();
                $table->boolean('matched')->default(false);
                $table->boolean('stop_processing')->default(false);
                $table->json('conditions_json')->nullable();
                $table->json('actions_json')->nullable();
                $table->json('action_results_json')->nullable();
                $table->timestamp('started_at')->nullable()->index();
                $table->timestamp('finished_at')->nullable()->index();
                $table->timestamps();

                $table->index(['email_message_id', 'created_at'], 'email_rule_attempts_message_created_index');
                $table->index(['email_rule_id', 'email_rule_version_id'], 'email_rule_attempts_rule_version_index');
            });
        }

        $this->backfillPublishedVersions();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_rule_execution_attempts');
        Schema::dropIfExists('email_rule_versions');

        if (! Schema::hasTable('email_rules')) {
            return;
        }

        Schema::table('email_rules', function (Blueprint $table): void {
            foreach (['published_at', 'published_by', 'published_version_id', 'lifecycle_status'] as $column) {
                if (Schema::hasColumn('email_rules', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function backfillPublishedVersions(): void
    {
        $now = now();
        $hasAccountPivot = Schema::hasTable('email_rule_accounts');

        DB::table('email_rules')
            ->whereNull('published_version_id')
            ->orderBy('id')
            ->get()
            ->each(function (object $rule) use ($hasAccountPivot, $now): void {
                $accountIds = $hasAccountPivot
                    ? DB::table('email_rule_accounts')
                        ->where('email_rule_id', $rule->id)
                        ->orderBy('email_account_id')
                        ->pluck('email_account_id')
                        ->map(fn ($id): int => (int) $id)
                        ->values()
                        ->all()
                    : [];

                $snapshot = [
                    'rule_id' => (int) $rule->id,
                    'version_number' => 1,
                    'trigger' => $rule->trigger ?? 'on_inbound',
                    'routing_phase' => $rule->routing_phase ?? 'normal',
                    'weight' => (int) ($rule->weight ?? 10),
                    'is_active' => (bool) ($rule->is_active ?? true),
                    'stop_processing' => (bool) ($rule->stop_processing ?? false),
                    'conditions' => json_decode((string) ($rule->conditions_json ?? '[]'), true) ?: [],
                    'actions' => json_decode((string) ($rule->actions_json ?? '[]'), true) ?: [],
                    'account_ids' => $accountIds,
                ];

                $versionId = DB::table('email_rule_versions')->insertGetId([
                    'email_rule_id' => $rule->id,
                    'version_number' => 1,
                    'status' => 'published',
                    'published_by' => $rule->updated_by ?? $rule->created_by,
                    'published_at' => $rule->updated_at ?? $now,
                    'name' => $rule->name,
                    'description' => $rule->description,
                    'trigger' => $snapshot['trigger'],
                    'routing_phase' => $snapshot['routing_phase'],
                    'weight' => $snapshot['weight'],
                    'is_active' => $snapshot['is_active'],
                    'stop_processing' => $snapshot['stop_processing'],
                    'conditions_json' => json_encode($snapshot['conditions']),
                    'actions_json' => json_encode($snapshot['actions']),
                    'account_ids_json' => json_encode($accountIds),
                    'snapshot_hash' => hash('sha256', json_encode($snapshot)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('email_rules')
                    ->where('id', $rule->id)
                    ->update([
                        'lifecycle_status' => 'published',
                        'published_version_id' => $versionId,
                        'published_by' => $rule->updated_by ?? $rule->created_by,
                        'published_at' => $rule->updated_at ?? $now,
                    ]);
            });
    }
};
