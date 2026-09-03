<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = ['email.rule_publish', 'email.rule_reprocess'];

    private const ROLES = ['Admin', 'Superuser'];

    public function up(): void
    {
        Schema::create('email_rule_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_rule_id')->unique()->constrained('email_rules')->cascadeOnDelete();
            $table->foreignId('base_email_rule_version_id')->nullable()->constrained('email_rule_versions')->nullOnDelete();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->json('payload_json');
            $table->string('checksum', 64)->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('email_rule_reprocess_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('email_rule_id')->constrained('email_rules')->cascadeOnDelete();
            $table->foreignId('email_rule_version_id')->constrained('email_rule_versions')->restrictOnDelete();
            $table->foreignId('parent_run_id')->nullable()->constrained('email_rule_reprocess_runs')->nullOnDelete();
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('operation')->index();
            $table->string('status')->index();
            $table->json('selection_json');
            $table->string('selection_hash', 64)->index();
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('matched_count')->default(0);
            $table->unsignedInteger('succeeded_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('overflow')->default(false);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_rule_reprocess_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_rule_reprocess_run_id')->constrained('email_rule_reprocess_runs')->cascadeOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->foreignId('email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->restrictOnDelete();
            $table->string('source_fingerprint', 64);
            $table->string('status')->default('previewed')->index();
            $table->string('reason_code')->nullable()->index();
            $table->boolean('matched')->default(false);
            $table->json('action_summary_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['email_rule_reprocess_run_id', 'email_message_id', 'email_mailbox_placement_id'],
                'email_rule_reprocess_items_source_unique',
            );
        });

        Schema::create('email_rule_action_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_rule_reprocess_item_id')->constrained('email_rule_reprocess_items')->cascadeOnDelete();
            $table->foreignId('email_rule_version_id')->constrained('email_rule_versions')->restrictOnDelete();
            $table->foreignId('email_message_id')->nullable()->constrained('email_messages')->nullOnDelete();
            $table->foreignId('email_mailbox_placement_id')->nullable()->constrained('email_mailbox_placements')->nullOnDelete();
            $table->unsignedInteger('action_position');
            $table->string('action_type', 80);
            $table->string('action_snapshot_hash', 64);
            $table->string('logical_key', 64)->index();
            $table->string('active_logical_key', 64)->nullable()->unique();
            $table->string('idempotency_key', 80)->unique();
            $table->unsignedInteger('attempt_number')->default(1);
            $table->string('status')->index();
            $table->string('reason_code')->nullable()->index();
            $table->json('result_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        $this->deployPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('email_rule_action_attempts');
        Schema::dropIfExists('email_rule_reprocess_items');
        Schema::dropIfExists('email_rule_reprocess_runs');
        Schema::dropIfExists('email_rule_drafts');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function deployPermissions(): void
    {
        $tables = config('permission.table_names');
        $columns = config('permission.column_names');
        $permissionsTable = $tables['permissions'];
        $rolesTable = $tables['roles'];
        $pivotTable = $tables['role_has_permissions'];
        $permissionKey = $columns['permission_pivot_key'] ?? 'permission_id';
        $roleKey = $columns['role_pivot_key'] ?? 'role_id';

        foreach (self::PERMISSIONS as $permission) {
            DB::table($permissionsTable)->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $permissionId = DB::table($permissionsTable)
                ->where('name', $permission)
                ->where('guard_name', 'web')
                ->value('id');

            foreach (self::ROLES as $roleName) {
                $roleId = DB::table($rolesTable)
                    ->where('name', $roleName)
                    ->where('guard_name', 'web')
                    ->value('id');
                if ($roleId !== null) {
                    DB::table($pivotTable)->insertOrIgnore([
                        $permissionKey => $permissionId,
                        $roleKey => $roleId,
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
