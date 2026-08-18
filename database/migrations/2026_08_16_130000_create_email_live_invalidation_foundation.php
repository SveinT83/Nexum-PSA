<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MIGRATION = '2026_08_16_130000_create_email_live_invalidation_foundation';

    private const STREAMS = 'email_live_projection_streams';

    private const CHANGES = 'email_live_projection_changes';

    private const PUBLICATIONS = 'email_live_projection_publications';

    private const DELIVERIES = 'email_live_projection_deliveries';

    private const GLOBAL_AUTHORITY = 'email_live_global_authority_states';

    private const ACCOUNT_AUTHORITY = 'email_live_account_authority_states';

    private const USER_ACCESS = 'email_live_user_access_states';

    private const USER_CONTENT_PATHS = 'email_live_user_content_authority_paths';

    private int $preflightStartedAt = 0;

    /** @var list<string> */
    private const TABLES = [
        self::STREAMS,
        self::CHANGES,
        self::PUBLICATIONS,
        self::DELIVERIES,
        self::GLOBAL_AUTHORITY,
        self::ACCOUNT_AUTHORITY,
        self::USER_ACCESS,
        self::USER_CONTENT_PATHS,
    ];

    public function up(): void
    {
        $this->preflightStartedAt = hrtime(true);
        $sealed = $this->repositoryIsSealed();
        if ($sealed) {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    throw new RuntimeException('Sealed Email live-invalidation schema evidence is missing.');
                }
            }

            $this->attestCurrentRows();
            $this->attestAuthorityBootstrap(false);
        }

        $this->addAuthorityColumns();
        $this->addAuthorityCursorIndexes();
        $this->createAuthorityTables();
        $this->createProjectionTables();
        $this->seedPristineAuthorityState($sealed);
        $this->attestCurrentRows();
        $this->attestAuthorityBootstrap(! $sealed);
        $this->replaceGuards();
        $this->attestCurrentRows();
        $this->attestAuthorityBootstrap(! $sealed);

        if (! $sealed && ($this->exists(self::STREAMS)
            || $this->exists(self::CHANGES)
            || $this->exists(self::PUBLICATIONS)
            || $this->exists(self::DELIVERIES))) {
            throw new RuntimeException('Email live-invalidation evidence exists before the schema seal.');
        }
    }

    public function down(): void
    {
        $this->preflightStartedAt = hrtime(true);
        $this->assertRollbackIsPristine();
        $this->dropGuards();

        foreach ([
            self::DELIVERIES,
            self::PUBLICATIONS,
            self::CHANGES,
            self::STREAMS,
            self::USER_CONTENT_PATHS,
            self::USER_ACCESS,
            self::ACCOUNT_AUTHORITY,
            self::GLOBAL_AUTHORITY,
        ] as $table) {
            Schema::dropIfExists($table);
        }

        $this->dropAuthorityCursorIndexes();
        $this->dropAuthorityColumns();
    }

    private function addAuthorityColumns(): void
    {
        $this->addUnsignedGeneration('user_management', 'email_live_enable_generation');
        $this->addUnsignedGeneration('email_accounts', 'email_live_owner_enable_generation');
        $this->addUnsignedGeneration('email_account_user_grants', 'email_live_enable_generation');
        $this->addUnsignedGeneration('email_mailbox_delegations', 'email_live_enable_generation');
        $this->addUnsignedGeneration('email_break_glass_accesses', 'email_live_enable_generation');

        foreach (['email_mailbox_delegations', 'email_break_glass_accesses'] as $table) {
            foreach (['email_live_start_invalidated_at', 'email_live_expiry_invalidated_at'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                        $blueprint->dateTime($column)->nullable();
                    });
                }
            }
        }
    }

    private function addUnsignedGeneration(string $table, string $column): void
    {
        if (Schema::hasColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->unsignedBigInteger($column)->default(1);
        });
    }

    private function addAuthorityCursorIndexes(): void
    {
        $indexes = [
            ['email_accounts', ['owner_id', 'id'], 'em_live_account_owner_cursor_ix'],
            ['email_account_user_grants', ['email_account_id', 'id'], 'em_live_grant_account_cursor_ix'],
            ['email_account_user_grants', ['user_id', 'id'], 'em_live_grant_user_cursor_ix'],
            ['email_mailbox_delegations', ['email_account_id', 'id'], 'em_live_delegate_account_cursor_ix'],
            ['email_mailbox_delegations', ['delegate_id', 'id'], 'em_live_delegate_user_cursor_ix'],
            ['email_mailbox_delegations', ['delegate_id', 'revoked_at', 'can_view', 'starts_at', 'id'], 'em_live_delegate_start_boundary_ix'],
            ['email_mailbox_delegations', ['delegate_id', 'revoked_at', 'can_view', 'expires_at', 'id'], 'em_live_delegate_expiry_boundary_ix'],
            ['email_break_glass_accesses', ['email_account_id', 'id'], 'em_live_break_account_cursor_ix'],
            ['email_break_glass_accesses', ['actor_id', 'id'], 'em_live_break_actor_cursor_ix'],
            ['email_break_glass_accesses', ['actor_id', 'revoked_at', 'can_view_content', 'starts_at', 'id'], 'em_live_break_start_boundary_ix'],
            ['email_break_glass_accesses', ['actor_id', 'revoked_at', 'can_view_content', 'expires_at', 'id'], 'em_live_break_expiry_boundary_ix'],
            ['user_management', ['status', 'id'], 'em_live_user_status_cursor_ix'],
            ['email_folders', ['account_id', 'id'], 'em_live_folder_account_cursor_ix'],
        ];

        foreach ($indexes as [$table, $columns, $name]) {
            $this->ensureIndex($table, $columns, $name);
        }
    }

    private function createAuthorityTables(): void
    {
        if (! Schema::hasTable(self::GLOBAL_AUTHORITY)) {
            Schema::create(self::GLOBAL_AUTHORITY, function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->unsignedBigInteger('active_user_generation')->default(1);
                $table->unsignedBigInteger('content_audience_generation')->default(1);
                $table->unsignedBigInteger('content_ability_generation')->default(1);
                $table->unsignedBigInteger('authorization_generation')->default(1);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable(self::ACCOUNT_AUTHORITY)) {
            Schema::create(self::ACCOUNT_AUTHORITY, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('email_account_id')->unique('em_live_account_authority_uq');
                $table->unsignedBigInteger('audience_generation')->default(1);
                $table->unsignedBigInteger('owner_user_id')->nullable();
                $table->unsignedBigInteger('owner_enable_generation')->default(1);
                $table->timestamps();
                $table->foreign('email_account_id', 'em_live_account_authority_account_fk')
                    ->references('id')->on('email_accounts')->restrictOnDelete();
                $table->foreign('owner_user_id', 'em_live_account_authority_owner_fk')
                    ->references('id')->on('user_management')->restrictOnDelete();
                $table->index(['email_account_id', 'id'], 'em_live_account_authority_cursor_ix');
            });
        }

        if (! Schema::hasTable(self::USER_ACCESS)) {
            Schema::create(self::USER_ACCESS, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique('em_live_user_access_uq');
                $table->unsignedBigInteger('authorization_epoch')->default(1);
                $table->unsignedBigInteger('content_ability_enable_generation')->default(1);
                $table->unsignedBigInteger('global_authorization_generation_seen')->default(1);
                $table->dateTime('next_boundary_at')->nullable();
                $table->dateTime('last_bounded_refresh_at')->nullable();
                $table->string('recompute_status', 16)->default('sealed');
                $table->string('recompute_phase', 20)->nullable();
                $table->unsignedBigInteger('delegation_through_id')->default(0);
                $table->unsignedBigInteger('break_glass_through_id')->default(0);
                $table->unsignedBigInteger('recompute_cursor_id')->default(0);
                $table->dateTime('recompute_boundary_at')->nullable();
                $table->char('claim_token', 64)->nullable();
                $table->unsignedBigInteger('page_through_id')->nullable();
                $table->unsignedSmallInteger('page_row_count')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->unsignedInteger('page_count')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->timestamps();
                $table->index(['recompute_status', 'next_boundary_at', 'id'], 'em_live_user_access_due_ix');
                $table->index(['user_id', 'next_boundary_at', 'id'], 'em_live_user_boundary_ix');
                $table->foreign('user_id', 'em_live_user_access_user_fk')
                    ->references('id')->on('user_management')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable(self::USER_CONTENT_PATHS)) {
            Schema::create(self::USER_CONTENT_PATHS, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('path_type', 24);
                $table->unsignedBigInteger('permission_id');
                $table->unsignedBigInteger('role_id')->nullable();
                $table->unsignedTinyInteger('direct_slot')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedBigInteger('enable_generation')->default(1);
                $table->dateTime('enabled_at');
                $table->dateTime('disabled_at')->nullable();
                $table->timestamps();
                $table->foreign('user_id', 'em_live_content_path_user_fk')
                    ->references('id')->on('user_management')->restrictOnDelete();
                $table->foreign('permission_id', 'em_live_content_path_permission_fk')
                    ->references('id')->on('permissions')->restrictOnDelete();
                $table->foreign('role_id', 'em_live_content_path_role_fk')
                    ->references('id')->on('roles')->restrictOnDelete();
                $table->unique(
                    ['user_id', 'permission_id', 'direct_slot'],
                    'em_live_content_path_direct_uq',
                );
                $table->unique(
                    ['user_id', 'permission_id', 'role_id'],
                    'em_live_content_path_role_uq',
                );
                $table->index(
                    ['user_id', 'enabled', 'permission_id', 'id'],
                    'em_live_content_path_user_ix',
                );
                $table->index(
                    ['enabled', 'permission_id', 'id'],
                    'em_live_content_path_global_ix',
                );
            });
        }
    }

    private function createProjectionTables(): void
    {
        if (! Schema::hasTable(self::STREAMS)) {
            Schema::create(self::STREAMS, function (Blueprint $table): void {
                $table->id();
                $table->string('stream_type', 16);
                $table->unsignedBigInteger('email_account_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedTinyInteger('global_slot')->nullable();
                $table->unsignedBigInteger('current_version')->default(0);
                $table->unsignedBigInteger('oldest_retained_version')->default(1);
                $table->unsignedBigInteger('acknowledged_version')->default(0);
                $table->dateTime('acknowledged_at')->nullable();
                $table->dateTime('last_changed_at')->nullable();
                $table->timestamps();
                $table->foreign('email_account_id', 'em_live_stream_account_fk')
                    ->references('id')->on('email_accounts')->restrictOnDelete();
                $table->foreign('user_id', 'em_live_stream_user_fk')
                    ->references('id')->on('user_management')->restrictOnDelete();
                $table->unique('email_account_id', 'em_live_stream_account_uq');
                $table->unique('user_id', 'em_live_stream_user_uq');
                $table->unique('global_slot', 'em_live_stream_global_uq');
                $table->index(['stream_type', 'id'], 'em_live_stream_type_cursor_ix');
            });
        }

        if (! Schema::hasTable(self::CHANGES)) {
            Schema::create(self::CHANGES, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('stream_id');
                $table->unsignedBigInteger('version');
                $table->unsignedBigInteger('email_account_id')->nullable();
                $table->char('idempotency_key', 64);
                $table->json('change_types_json');
                $table->json('conversation_ids_json')->nullable();
                $table->json('placement_ids_json')->nullable();
                $table->unsignedTinyInteger('conversation_id_count')->default(0);
                $table->unsignedTinyInteger('placement_id_count')->default(0);
                $table->boolean('truncated')->default(false);
                $table->string('publication_status', 16)->default('pending');
                $table->dateTime('available_at');
                $table->char('claim_token', 64)->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->dateTime('sealed_at')->nullable();
                $table->dateTime('retention_ready_at')->nullable();
                $table->unsignedInteger('compact_delivery_count')->default(0);
                $table->unsignedInteger('compact_appended_count')->default(0);
                $table->unsignedInteger('compact_suppressed_count')->default(0);
                $table->string('error_code', 80)->nullable();
                $table->timestamps();
                $table->foreign('stream_id', 'em_live_change_stream_fk')
                    ->references('id')->on(self::STREAMS)->restrictOnDelete();
                $table->foreign('email_account_id', 'em_live_change_account_fk')
                    ->references('id')->on('email_accounts')->restrictOnDelete();
                $table->unique(['stream_id', 'version'], 'em_live_change_stream_version_uq');
                $table->unique(['stream_id', 'idempotency_key'], 'em_live_change_idempotency_uq');
                $table->unique(['id', 'stream_id'], 'em_live_change_id_stream_uq');
                $table->index(['publication_status', 'next_attempt_at', 'id'], 'em_live_change_recovery_ix');
                $table->index(['stream_id', 'publication_status', 'version'], 'em_live_change_stream_status_ix');
            });
        }

        if (! Schema::hasTable(self::PUBLICATIONS)) {
            Schema::create(self::PUBLICATIONS, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('source_change_id')->unique('em_live_publication_source_uq');
                $table->unsignedBigInteger('source_stream_id');
                $table->string('source_stream_type', 16);
                $table->unsignedBigInteger('email_account_id')->nullable();
                $table->dateTime('source_at');
                $table->unsignedBigInteger('frozen_owner_user_id')->nullable();
                $table->unsignedBigInteger('account_audience_generation')->nullable();
                $table->unsignedBigInteger('global_active_user_generation')->nullable();
                $table->unsignedBigInteger('global_content_audience_generation');
                $table->unsignedBigInteger('global_content_ability_generation');
                $table->unsignedBigInteger('grant_through_id')->default(0);
                $table->unsignedBigInteger('delegation_through_id')->default(0);
                $table->unsignedBigInteger('break_glass_through_id')->default(0);
                $table->unsignedBigInteger('active_user_through_id')->default(0);
                $table->string('phase', 20);
                $table->unsignedBigInteger('candidate_cursor_id')->default(0);
                $table->string('status', 16)->default('pending');
                $table->char('claim_token', 64)->nullable();
                $table->unsignedBigInteger('page_through_id')->nullable();
                $table->unsignedSmallInteger('page_row_count')->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->unsignedInteger('page_count')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->string('delivery_summary_status', 16)->default('waiting');
                $table->unsignedBigInteger('delivery_through_id')->nullable();
                $table->unsignedBigInteger('delivery_cursor_id')->default(0);
                $table->unsignedInteger('delivery_count')->default(0);
                $table->unsignedInteger('delivery_appended_count')->default(0);
                $table->unsignedInteger('delivery_suppressed_count')->default(0);
                $table->char('delivery_claim_token', 64)->nullable();
                $table->unsignedBigInteger('delivery_page_through_id')->nullable();
                $table->unsignedSmallInteger('delivery_page_row_count')->nullable();
                $table->unsignedSmallInteger('delivery_attempt_count')->default(0);
                $table->unsignedInteger('delivery_page_count')->default(0);
                $table->dateTime('delivery_last_attempt_at')->nullable();
                $table->dateTime('delivery_next_attempt_at')->nullable();
                $table->dateTime('delivery_sealed_at')->nullable();
                $table->string('delivery_error_code', 80)->nullable();
                $table->timestamps();
                $table->foreign(['source_change_id', 'source_stream_id'], 'em_live_publication_change_fk')
                    ->references(['id', 'stream_id'])->on(self::CHANGES)->restrictOnDelete();
                $table->foreign('email_account_id', 'em_live_publication_account_fk')
                    ->references('id')->on('email_accounts')->restrictOnDelete();
                $table->unique(['id', 'source_change_id'], 'em_live_publication_id_source_uq');
                $table->index(['status', 'next_attempt_at', 'id'], 'em_live_publication_recovery_ix');
                $table->index(
                    ['delivery_summary_status', 'delivery_next_attempt_at', 'id'],
                    'em_live_publication_delivery_recovery_ix',
                );
                $table->index(['source_stream_type', 'phase', 'candidate_cursor_id', 'id'], 'em_live_publication_phase_ix');
            });
        }

        if (! Schema::hasTable(self::DELIVERIES)) {
            Schema::create(self::DELIVERIES, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('publication_id');
                $table->unsignedBigInteger('source_change_id');
                $table->unsignedBigInteger('user_id');
                $table->string('authority_kind', 20)->nullable();
                $table->unsignedBigInteger('authority_id')->nullable();
                $table->unsignedBigInteger('authority_enable_generation')->nullable();
                $table->unsignedBigInteger('content_authority_path_id')->nullable();
                $table->unsignedBigInteger('frozen_content_authority_generation')->nullable();
                $table->unsignedBigInteger('frozen_user_authorization_epoch');
                $table->unsignedBigInteger('derived_change_id')->nullable()->unique('em_live_delivery_derived_uq');
                $table->unsignedBigInteger('derived_stream_id')->nullable();
                $table->string('status', 16)->default('pending');
                $table->char('claim_token', 64)->nullable();
                $table->unsignedSmallInteger('attempt_count')->default(0);
                $table->dateTime('last_attempt_at')->nullable();
                $table->dateTime('next_attempt_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->string('error_code', 80)->nullable();
                $table->timestamps();
                $table->foreign(['publication_id', 'source_change_id'], 'em_live_delivery_publication_fk')
                    ->references(['id', 'source_change_id'])->on(self::PUBLICATIONS)->restrictOnDelete();
                $table->foreign(['derived_change_id', 'derived_stream_id'], 'em_live_delivery_derived_fk')
                    ->references(['id', 'stream_id'])->on(self::CHANGES)->restrictOnDelete();
                $table->foreign('user_id', 'em_live_delivery_user_fk')
                    ->references('id')->on('user_management')->restrictOnDelete();
                $table->foreign('content_authority_path_id', 'em_live_delivery_content_path_fk')
                    ->references('id')->on(self::USER_CONTENT_PATHS)->restrictOnDelete();
                $table->unique(['source_change_id', 'user_id'], 'em_live_delivery_source_user_uq');
                $table->index(['publication_id', 'status', 'id'], 'em_live_delivery_publication_ix');
                $table->index(['status', 'next_attempt_at', 'id'], 'em_live_delivery_recovery_ix');
            });
        }
    }

    private function seedPristineAuthorityState(bool $sealed): void
    {
        if (! DB::table(self::GLOBAL_AUTHORITY)->where('id', 1)->exists()) {
            if ($sealed) {
                throw new RuntimeException('Sealed Email live global authority evidence is missing.');
            }
            DB::table(self::GLOBAL_AUTHORITY)->insert([
                'id' => 1,
                'active_user_generation' => 1,
                'content_audience_generation' => 1,
                'content_ability_generation' => 1,
                'authorization_generation' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! $sealed) {
            $now = now();
            DB::table(self::ACCOUNT_AUTHORITY)->insertUsing(
                [
                    'email_account_id',
                    'audience_generation',
                    'owner_user_id',
                    'owner_enable_generation',
                    'created_at',
                    'updated_at',
                ],
                DB::table('email_accounts as source')
                    ->select([
                        'source.id',
                        DB::raw('1'),
                        'source.owner_id',
                        'source.email_live_owner_enable_generation',
                        DB::raw(DB::connection()->getPdo()->quote($now->toDateTimeString())),
                        DB::raw(DB::connection()->getPdo()->quote($now->toDateTimeString())),
                    ])
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from(self::ACCOUNT_AUTHORITY.' as existing')
                            ->whereColumn('existing.email_account_id', 'source.id');
                    })
            );
            DB::table(self::USER_ACCESS)->insertUsing(
                [
                    'user_id',
                    'authorization_epoch',
                    'content_ability_enable_generation',
                    'global_authorization_generation_seen',
                    'recompute_status',
                    'recompute_phase',
                    'delegation_through_id',
                    'break_glass_through_id',
                    'recompute_boundary_at',
                    'created_at',
                    'updated_at',
                ],
                DB::table('user_management as source')
                    ->select([
                        'source.id',
                        DB::raw('1'),
                        DB::raw('1'),
                        DB::raw('1'),
                        DB::raw("'pending'"),
                        DB::raw("'delegations'"),
                        DB::raw('(select coalesce(max(delegation.id), 0) from email_mailbox_delegations as delegation where delegation.delegate_id = source.id)'),
                        DB::raw('(select coalesce(max(access_row.id), 0) from email_break_glass_accesses as access_row where access_row.actor_id = source.id)'),
                        DB::raw(DB::connection()->getPdo()->quote($now->toDateTimeString())),
                        DB::raw(DB::connection()->getPdo()->quote($now->toDateTimeString())),
                        DB::raw(DB::connection()->getPdo()->quote($now->toDateTimeString())),
                    ])
                    ->whereNotExists(function ($query): void {
                        $query->selectRaw('1')
                            ->from(self::USER_ACCESS.' as existing')
                            ->whereColumn('existing.user_id', 'source.id');
                    })
            );
            $this->seedPristineContentAuthorityPaths($now->toDateTimeString());
        }
    }

    private function seedPristineContentAuthorityPaths(string $now): void
    {
        $userModel = DB::connection()->getPdo()->quote(App\Models\Core\User::class);
        $timestamp = DB::connection()->getPdo()->quote($now);

        DB::table(self::USER_CONTENT_PATHS)->insertUsing(
            [
                'user_id',
                'path_type',
                'permission_id',
                'role_id',
                'direct_slot',
                'enabled',
                'enable_generation',
                'enabled_at',
                'created_at',
                'updated_at',
            ],
            DB::table('model_has_permissions as assignment')
                ->join('permissions as permission', 'permission.id', '=', 'assignment.permission_id')
                ->select([
                    'assignment.model_id',
                    DB::raw("'direct_permission'"),
                    'permission.id',
                    DB::raw('null'),
                    DB::raw('1'),
                    DB::raw('1'),
                    DB::raw('1'),
                    DB::raw($timestamp),
                    DB::raw($timestamp),
                    DB::raw($timestamp),
                ])
                ->where('permission.name', 'email.inbox_view')
                ->whereRaw("assignment.model_type = {$userModel}")
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from(self::USER_CONTENT_PATHS.' as existing')
                        ->whereColumn('existing.user_id', 'assignment.model_id')
                        ->whereColumn('existing.permission_id', 'permission.id')
                        ->where('existing.direct_slot', 1);
                })
        );

        DB::table(self::USER_CONTENT_PATHS)->insertUsing(
            [
                'user_id',
                'path_type',
                'permission_id',
                'role_id',
                'direct_slot',
                'enabled',
                'enable_generation',
                'enabled_at',
                'created_at',
                'updated_at',
            ],
            DB::table('model_has_roles as assignment')
                ->join('role_has_permissions as role_permission', 'role_permission.role_id', '=', 'assignment.role_id')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->select([
                    'assignment.model_id',
                    DB::raw("'role_membership'"),
                    'permission.id',
                    'assignment.role_id',
                    DB::raw('null'),
                    DB::raw('1'),
                    DB::raw('1'),
                    DB::raw($timestamp),
                    DB::raw($timestamp),
                    DB::raw($timestamp),
                ])
                ->where('permission.name', 'email.inbox_view')
                ->whereRaw("assignment.model_type = {$userModel}")
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')
                        ->from(self::USER_CONTENT_PATHS.' as existing')
                        ->whereColumn('existing.user_id', 'assignment.model_id')
                        ->whereColumn('existing.permission_id', 'permission.id')
                        ->whereColumn('existing.role_id', 'assignment.role_id');
                })
        );
    }

    private function replaceGuards(): void
    {
        $contracts = [
            [self::STREAMS, 'em_live_stream_contract', $this->streamInsertContract(), $this->streamUpdateContract()],
            [self::CHANGES, 'em_live_change_contract', $this->changeInsertContract(), $this->changeUpdateContract()],
            [self::PUBLICATIONS, 'em_live_publication_contract', $this->publicationInsertContract(), $this->publicationUpdateContract()],
            [self::DELIVERIES, 'em_live_delivery_contract', $this->deliveryInsertContract(), $this->deliveryUpdateContract()],
            [self::GLOBAL_AUTHORITY, 'em_live_global_authority_contract', $this->globalAuthorityInsertContract(), $this->globalAuthorityUpdateContract()],
            [self::ACCOUNT_AUTHORITY, 'em_live_account_authority_contract', $this->accountAuthorityInsertContract(), $this->accountAuthorityUpdateContract()],
            [self::USER_ACCESS, 'em_live_user_access_contract', $this->userAccessInsertContract(), $this->userAccessUpdateContract()],
            [self::USER_CONTENT_PATHS, 'em_live_content_path_contract', $this->contentPathInsertContract(), $this->contentPathUpdateContract()],
        ];
        foreach ($contracts as [$table, $name, $insertValid, $updateValid]) {
            $this->replaceContractGuard($table, $name, $insertValid, $updateValid);
        }

        $this->replaceNoDeleteGuard(self::STREAMS, 'em_live_stream_contract_no_delete');
        $this->replaceConditionalDeleteGuard(
            self::CHANGES,
            'em_live_change_contract_no_delete',
            $this->changeDeleteContract('OLD.'),
        );
        $this->replaceConditionalDeleteGuard(
            self::PUBLICATIONS,
            'em_live_publication_contract_no_delete',
            $this->publicationDeleteContract('OLD.'),
        );
        $this->replaceConditionalDeleteGuard(
            self::DELIVERIES,
            'em_live_delivery_contract_no_delete',
            $this->deliveryDeleteContract('OLD.'),
        );
        foreach ([
            [self::GLOBAL_AUTHORITY, 'em_live_global_authority_contract_no_delete'],
            [self::ACCOUNT_AUTHORITY, 'em_live_account_authority_contract_no_delete'],
            [self::USER_ACCESS, 'em_live_user_access_contract_no_delete'],
            [self::USER_CONTENT_PATHS, 'em_live_content_path_contract_no_delete'],
        ] as [$table, $trigger]) {
            $this->replaceNoDeleteGuard($table, $trigger);
        }

        $this->replaceBaseAuthorityGuards();
    }

    private function attestCurrentRows(): void
    {
        $contracts = [
            [self::STREAMS, $this->streamContract('')],
            [self::CHANGES, $this->changeContract('').' and ('.$this->changeLinkContract('').')'],
            [self::PUBLICATIONS, $this->publicationContract('').' and ('.$this->publicationLinkContract('').')'],
            [self::DELIVERIES, $this->deliveryContract('').' and ('.$this->deliveryLinkContract('').')'],
            [self::GLOBAL_AUTHORITY, $this->globalAuthorityContract('')],
            [self::ACCOUNT_AUTHORITY, $this->accountAuthorityContract('')],
            [self::USER_ACCESS, $this->userAccessContract('')],
            [self::USER_CONTENT_PATHS, $this->contentAuthorityPathContract('')],
        ];
        foreach ($contracts as [$table, $valid]) {
            $this->assertPreflightDeadline();
            if (DB::table($table)->whereRaw("coalesce(({$valid}), 0) = 0")->limit(1)->exists()) {
                throw new RuntimeException("Malformed Email live-invalidation state in {$table}.");
            }
        }
    }

    private function attestAuthorityBootstrap(bool $requirePristine): void
    {
        $this->assertPreflightDeadline();
        if (DB::table('email_accounts as account')
            ->leftJoin(self::ACCOUNT_AUTHORITY.' as authority', 'authority.email_account_id', '=', 'account.id')
            ->where(function ($query): void {
                $query->whereNull('authority.id')
                    ->orWhereColumn('authority.owner_enable_generation', '<>', 'account.email_live_owner_enable_generation')
                    ->orWhereRaw('not ((authority.owner_user_id = account.owner_id) or (authority.owner_user_id is null and account.owner_id is null))')
                    ->orWhereColumn('authority.owner_enable_generation', '>', 'authority.audience_generation');
            })
            ->limit(1)
            ->exists()) {
            throw new RuntimeException('Email live account authority bootstrap is incomplete.');
        }

        $this->assertPreflightDeadline();
        if (DB::table('user_management as source')
            ->leftJoin(self::USER_ACCESS.' as access_state', 'access_state.user_id', '=', 'source.id')
            ->whereNull('access_state.id')
            ->limit(1)
            ->exists()) {
            throw new RuntimeException('Email live user access bootstrap is incomplete.');
        }

        if (! $requirePristine) {
            return;
        }

        $this->assertPreflightDeadline();
        foreach ([
            ['email_accounts', 'email_live_owner_enable_generation'],
            ['user_management', 'email_live_enable_generation'],
            ['email_account_user_grants', 'email_live_enable_generation'],
            ['email_mailbox_delegations', 'email_live_enable_generation'],
            ['email_break_glass_accesses', 'email_live_enable_generation'],
        ] as [$table, $column]) {
            if (DB::table($table)->where($column, '<>', 1)->limit(1)->exists()) {
                throw new RuntimeException('Email live base generations are not pristine.');
            }
            $this->assertPreflightDeadline();
        }

        if (DB::table('email_mailbox_delegations')
            ->whereNotNull('email_live_start_invalidated_at')
            ->orWhereNotNull('email_live_expiry_invalidated_at')
            ->limit(1)
            ->exists()
            || DB::table('email_break_glass_accesses')
                ->whereNotNull('email_live_start_invalidated_at')
                ->orWhereNotNull('email_live_expiry_invalidated_at')
                ->limit(1)
                ->exists()) {
            throw new RuntimeException('Email live boundary evidence exists before the first seal.');
        }

        $this->assertPreflightDeadline();
        if (DB::table(self::GLOBAL_AUTHORITY)
            ->where(function ($query): void {
                $query->where('id', '<>', 1)
                    ->orWhere('active_user_generation', '<>', 1)
                    ->orWhere('content_audience_generation', '<>', 1)
                    ->orWhere('content_ability_generation', '<>', 1)
                    ->orWhere('authorization_generation', '<>', 1);
            })->limit(1)->exists()
            || DB::table(self::GLOBAL_AUTHORITY)->count() !== 1) {
            throw new RuntimeException('Email live global authority bootstrap is not pristine.');
        }

        $this->assertPreflightDeadline();
        if (DB::table(self::ACCOUNT_AUTHORITY)
            ->where(function ($query): void {
                $query->where('audience_generation', '<>', 1)
                    ->orWhere('owner_enable_generation', '<>', 1);
            })->limit(1)->exists()) {
            throw new RuntimeException('Email live account authority bootstrap is not pristine.');
        }

        $this->assertPreflightDeadline();
        if (DB::table(self::USER_ACCESS.' as access_state')
            ->where(function ($query): void {
                $query->where('authorization_epoch', '<>', 1)
                    ->orWhere('content_ability_enable_generation', '<>', 1)
                    ->orWhere('global_authorization_generation_seen', '<>', 1)
                    ->orWhere('recompute_status', '<>', 'pending')
                    ->orWhere('recompute_phase', '<>', 'delegations')
                    ->orWhere('recompute_cursor_id', '<>', 0)
                    ->orWhere('attempt_count', '<>', 0)
                    ->orWhere('page_count', '<>', 0)
                    ->orWhereNotNull('completed_at')
                    ->orWhereRaw('delegation_through_id <> (select coalesce(max(candidate.id), 0) from email_mailbox_delegations as candidate where candidate.delegate_id = access_state.user_id)')
                    ->orWhereRaw('break_glass_through_id <> (select coalesce(max(candidate.id), 0) from email_break_glass_accesses as candidate where candidate.actor_id = access_state.user_id)');
            })->limit(1)->exists()) {
            throw new RuntimeException('Email live user access bootstrap is not pristine.');
        }

        $this->attestPristineContentAuthorityPaths();
    }

    private function attestPristineContentAuthorityPaths(): void
    {
        $userModel = App\Models\Core\User::class;
        $this->assertPreflightDeadline();
        if (DB::table(self::USER_CONTENT_PATHS.' as path')
            ->where(function ($query) use ($userModel): void {
                $query->where('path.enabled', '<>', 1)
                    ->orWhere('path.enable_generation', '<>', 1)
                    ->orWhere(function ($query) use ($userModel): void {
                        $query->where('path.path_type', 'direct_permission')
                            ->whereNotExists(function ($query) use ($userModel): void {
                                $query->selectRaw('1')
                                    ->from('model_has_permissions as assignment')
                                    ->whereColumn('assignment.model_id', 'path.user_id')
                                    ->whereColumn('assignment.permission_id', 'path.permission_id')
                                    ->where('assignment.model_type', $userModel);
                            });
                    })
                    ->orWhere(function ($query) use ($userModel): void {
                        $query->where('path.path_type', 'role_membership')
                            ->whereNotExists(function ($query) use ($userModel): void {
                                $query->selectRaw('1')
                                    ->from('model_has_roles as assignment')
                                    ->join('role_has_permissions as role_permission', function ($join): void {
                                        $join->on('role_permission.role_id', '=', 'assignment.role_id')
                                            ->on('role_permission.permission_id', '=', 'path.permission_id');
                                    })
                                    ->whereColumn('assignment.model_id', 'path.user_id')
                                    ->whereColumn('assignment.role_id', 'path.role_id')
                                    ->where('assignment.model_type', $userModel);
                            });
                    });
            })->limit(1)->exists()) {
            throw new RuntimeException('Email live content-authority paths contain extra bootstrap rows.');
        }

        $this->assertPreflightDeadline();
        if ($this->missingPristineDirectContentPath($userModel)
            || $this->missingPristineRoleContentPath($userModel)) {
            throw new RuntimeException('Email live content-authority path bootstrap is incomplete.');
        }
    }

    private function missingPristineDirectContentPath(string $userModel): bool
    {
        return DB::table('model_has_permissions as assignment')
            ->join('permissions as permission', 'permission.id', '=', 'assignment.permission_id')
            ->where('permission.name', 'email.inbox_view')
            ->where('assignment.model_type', $userModel)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(self::USER_CONTENT_PATHS.' as path')
                    ->whereColumn('path.user_id', 'assignment.model_id')
                    ->whereColumn('path.permission_id', 'permission.id')
                    ->where('path.path_type', 'direct_permission')
                    ->where('path.direct_slot', 1);
            })->limit(1)->exists();
    }

    private function missingPristineRoleContentPath(string $userModel): bool
    {
        return DB::table('model_has_roles as assignment')
            ->join('role_has_permissions as role_permission', 'role_permission.role_id', '=', 'assignment.role_id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('permission.name', 'email.inbox_view')
            ->where('assignment.model_type', $userModel)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from(self::USER_CONTENT_PATHS.' as path')
                    ->whereColumn('path.user_id', 'assignment.model_id')
                    ->whereColumn('path.permission_id', 'permission.id')
                    ->whereColumn('path.role_id', 'assignment.role_id')
                    ->where('path.path_type', 'role_membership');
            })->limit(1)->exists();
    }

    private function assertPreflightDeadline(): void
    {
        $seconds = max(1, (int) config('email_live.migration_preflight_seconds', 30));
        if ($this->preflightStartedAt > 0
            && hrtime(true) - $this->preflightStartedAt > $seconds * 1_000_000_000) {
            throw new RuntimeException('Email live-invalidation schema preflight exceeded its deadline.');
        }
    }

    private function changeLinkContract(string $prefix): string
    {
        return 'exists(select 1 from '.self::STREAMS.' as linked_stream'
            ." where linked_stream.id = {$prefix}stream_id"
            ." and {$prefix}version <= linked_stream.current_version"
            ." and ((linked_stream.stream_type = 'account'"
            ." and {$prefix}email_account_id = linked_stream.email_account_id)"
            ." or (linked_stream.stream_type = 'global' and {$prefix}email_account_id is null)"
            ." or linked_stream.stream_type = 'user'))";
    }

    private function publicationLinkContract(string $prefix): string
    {
        return 'exists(select 1 from '.self::CHANGES.' as source_change'
            .' join '.self::STREAMS.' as source_stream on source_stream.id = source_change.stream_id'
            ." where source_change.id = {$prefix}source_change_id"
            ." and source_change.stream_id = {$prefix}source_stream_id"
            ." and (({$prefix}source_stream_type = 'account'"
            ." and source_stream.stream_type = 'account'"
            ." and {$prefix}email_account_id = source_stream.email_account_id"
            .' and source_change.email_account_id = source_stream.email_account_id)'
            ." or ({$prefix}source_stream_type = 'global'"
            ." and source_stream.stream_type = 'global'"
            ." and {$prefix}email_account_id is null and source_change.email_account_id is null)))";
    }

    private function deliveryLinkContract(string $prefix): string
    {
        return 'exists(select 1 from '.self::PUBLICATIONS.' as source_publication'
            ." where source_publication.id = {$prefix}publication_id"
            ." and source_publication.source_change_id = {$prefix}source_change_id)"
            ." and (({$prefix}derived_change_id is null and {$prefix}derived_stream_id is null)"
            .' or exists(select 1 from '.self::CHANGES.' as derived_change'
            .' join '.self::STREAMS.' as derived_stream on derived_stream.id = derived_change.stream_id'
            ." where derived_change.id = {$prefix}derived_change_id"
            ." and derived_change.stream_id = {$prefix}derived_stream_id"
            ." and derived_stream.stream_type = 'user'"
            ." and derived_stream.user_id = {$prefix}user_id))"
            ." and ({$prefix}content_authority_path_id is null"
            .' or exists(select 1 from '.self::USER_CONTENT_PATHS.' as content_path'
            ." where content_path.id = {$prefix}content_authority_path_id"
            ." and content_path.user_id = {$prefix}user_id))";
    }

    private function deliveryAppendAuthorityContract(string $prefix): string
    {
        return 'exists(select 1 from '.self::USER_CONTENT_PATHS.' as content_path'
            .' join '.self::PUBLICATIONS.' as source_publication'
            ." on source_publication.id = {$prefix}publication_id"
            ." and source_publication.source_change_id = {$prefix}source_change_id"
            ." where content_path.id = {$prefix}content_authority_path_id"
            ." and content_path.user_id = {$prefix}user_id and content_path.enabled = 1"
            ." and content_path.enable_generation = {$prefix}frozen_content_authority_generation"
            .' and content_path.enable_generation <= source_publication.global_content_ability_generation'
            .' and content_path.enabled_at <= source_publication.source_at)';
    }

    private function assertRollbackIsPristine(): void
    {
        foreach ([self::STREAMS, self::CHANGES, self::PUBLICATIONS, self::DELIVERIES] as $table) {
            $this->assertPreflightDeadline();
            if (Schema::hasTable($table) && $this->exists($table)) {
                throw new RuntimeException('Email live-invalidation evidence must be preserved before schema rollback.');
            }
        }
        $this->attestCurrentRows();
        $this->attestAuthorityBootstrap(true);
    }

    private function streamContract(string $prefix): string
    {
        return "{$prefix}stream_type in ('global','account','user')"
            ." and (({$prefix}stream_type = 'global' and {$prefix}global_slot = 1"
            ." and {$prefix}email_account_id is null and {$prefix}user_id is null)"
            ." or ({$prefix}stream_type = 'account' and {$prefix}email_account_id >= 1"
            ." and {$prefix}user_id is null and {$prefix}global_slot is null)"
            ." or ({$prefix}stream_type = 'user' and {$prefix}user_id >= 1"
            ." and {$prefix}email_account_id is null and {$prefix}global_slot is null))"
            ." and {$prefix}current_version >= 0 and {$prefix}oldest_retained_version >= 1"
            ." and {$prefix}oldest_retained_version <= {$prefix}current_version + 1"
            ." and {$prefix}acknowledged_version between 0 and {$prefix}current_version"
            ." and (({$prefix}stream_type = 'user'"
            ." and (({$prefix}acknowledged_version = 0 and {$prefix}acknowledged_at is null)"
            ." or ({$prefix}acknowledged_version >= 1 and {$prefix}acknowledged_at is not null))"
            ." and {$prefix}oldest_retained_version <= {$prefix}acknowledged_version + 1)"
            ." or ({$prefix}stream_type <> 'user' and {$prefix}acknowledged_version = 0"
            ." and {$prefix}acknowledged_at is null))"
            ." and (({$prefix}current_version = 0 and {$prefix}last_changed_at is null)"
            ." or ({$prefix}current_version >= 1 and {$prefix}last_changed_at is not null))";
    }

    private function changeContract(string $prefix): string
    {
        $types = $prefix.'change_types_json';
        $conversations = $prefix.'conversation_ids_json';
        $placements = $prefix.'placement_ids_json';

        return "{$prefix}version >= 1 and length({$prefix}idempotency_key) = 64"
            ." and {$prefix}conversation_id_count between 0 and 50"
            ." and {$prefix}placement_id_count between 0 and 50 and {$prefix}truncated in (0,1)"
            ." and ({$this->changeTypesJsonContract($types)})"
            ." and (({$prefix}conversation_id_count = 0 and {$conversations} is null)"
            ." or ({$prefix}conversation_id_count > 0"
            ." and ({$this->positiveUniqueIdJsonContract($conversations, $prefix.'conversation_id_count')})))"
            ." and (({$prefix}placement_id_count = 0 and {$placements} is null)"
            ." or ({$prefix}placement_id_count > 0"
            ." and ({$this->positiveUniqueIdJsonContract($placements, $prefix.'placement_id_count')})))"
            ." and {$prefix}publication_status in ('pending','running','published','sealed','blocked')"
            ." and {$prefix}attempt_count between 0 and 3"
            ." and (({$prefix}publication_status = 'pending' and {$prefix}claim_token is null"
            ." and {$prefix}published_at is null and {$prefix}sealed_at is null"
            ." and ({$prefix}error_code is null or {$prefix}error_code in"
            ." ('email_live_append_failed','email_live_transport_failed')))"
            ." or ({$prefix}publication_status = 'running' and {$prefix}claim_token is not null"
            ." and length({$prefix}claim_token) = 64 and {$prefix}attempt_count between 1 and 3"
            ." and {$prefix}last_attempt_at is not null and {$prefix}published_at is null"
            ." and {$prefix}sealed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}publication_status = 'published' and {$prefix}claim_token is null"
            ." and {$prefix}published_at is not null and {$prefix}sealed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}publication_status = 'sealed' and {$prefix}claim_token is null"
            ." and {$prefix}published_at is null and {$prefix}sealed_at is not null and {$prefix}error_code is null)"
            ." or ({$prefix}publication_status = 'blocked' and {$prefix}claim_token is null"
            ." and {$prefix}attempt_count = 3 and {$prefix}last_attempt_at is not null"
            ." and {$prefix}published_at is null and {$prefix}sealed_at is null"
            ." and {$prefix}error_code in ('email_live_append_failed','email_live_transport_failed','email_live_attempts_exhausted')))"
            ." and {$prefix}compact_delivery_count = {$prefix}compact_appended_count"
            ." + {$prefix}compact_suppressed_count"
            ." and (({$prefix}retention_ready_at is null"
            ." and {$prefix}compact_delivery_count = 0 and {$prefix}compact_appended_count = 0"
            ." and {$prefix}compact_suppressed_count = 0)"
            ." or ({$prefix}retention_ready_at is not null"
            ." and {$prefix}publication_status = 'sealed' and {$prefix}sealed_at is not null))";
    }

    private function changeTypesJsonContract(string $column): string
    {
        $allowed = [
            'mail_projection',
            'personal_state',
            'authorization',
            'collaboration',
            'taxonomy',
            'ticket_link',
            'account_state',
        ];
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $members = collect($allowed)
                ->map(fn (string $type): string => "json_contains({$column}, json_quote('{$type}'))")
                ->implode(' + ');

            return "json_valid({$column}) and lower(json_type({$column})) = 'array'"
                ." and json_length({$column}) between 1 and 7"
                ." and json_length({$column}) = ({$members})";
        }
        $members = collect($allowed)
            ->map(fn (string $type): string => "exists(select 1 from json_each({$column})"
                ." where type = 'text' and value = '{$type}')")
            ->implode(' + ');

        return "json_valid({$column}) and lower(json_type({$column})) = 'array'"
            ." and json_array_length({$column}) between 1 and 7"
            ." and json_array_length({$column}) = ({$members})";
    }

    private function positiveUniqueIdJsonContract(string $column, string $declaredCount): string
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $value = "json_extract({$column}, concat('$[', member.ordinality - 1, ']'))";

            return "json_valid({$column}) and lower(json_type({$column})) = 'array'"
                ." and json_length({$column}) = {$declaredCount}"
                ." and not exists(select 1 from json_table({$column}, '$[*]'"
                .' columns(ordinality for ordinality)) as member'
                ." where lower(json_type({$value})) <> 'integer'"
                ." or cast(json_unquote({$value}) as unsigned) < 1)"
                ." and (select count(distinct json_unquote({$value}))"
                ." from json_table({$column}, '$[*]' columns(ordinality for ordinality)) as member)"
                ." = {$declaredCount}";
        }

        return "json_valid({$column}) and lower(json_type({$column})) = 'array'"
            ." and json_array_length({$column}) = {$declaredCount}"
            ." and not exists(select 1 from json_each({$column})"
            ." where type <> 'integer' or atom < 1)"
            ." and (select count(distinct atom) from json_each({$column})) = {$declaredCount}";
    }

    private function publicationContract(string $prefix): string
    {
        $phaseThrough = "case {$prefix}phase"
            ." when 'owner' then case when {$prefix}frozen_owner_user_id is null then 0 else 1 end"
            ." when 'grants' then {$prefix}grant_through_id"
            ." when 'delegations' then {$prefix}delegation_through_id"
            ." when 'break_glass' then {$prefix}break_glass_through_id"
            ." when 'active_users' then {$prefix}active_user_through_id"
            ." else {$prefix}candidate_cursor_id end";

        return "{$prefix}source_change_id >= 1 and {$prefix}source_stream_id >= 1"
            ." and {$prefix}source_stream_type in ('account','global')"
            ." and (({$prefix}source_stream_type = 'account' and {$prefix}email_account_id >= 1"
            ." and {$prefix}account_audience_generation >= 1"
            ." and {$prefix}global_active_user_generation is null"
            ." and {$prefix}phase in ('owner','grants','delegations','break_glass','sealed'))"
            ." or ({$prefix}source_stream_type = 'global' and {$prefix}email_account_id is null"
            ." and {$prefix}account_audience_generation is null"
            ." and {$prefix}global_active_user_generation >= 1"
            ." and {$prefix}phase in ('active_users','sealed')))"
            ." and {$prefix}global_content_audience_generation >= 1"
            ." and {$prefix}global_content_ability_generation >= 1"
            ." and {$prefix}grant_through_id >= 0 and {$prefix}delegation_through_id >= 0"
            ." and {$prefix}break_glass_through_id >= 0 and {$prefix}active_user_through_id >= 0"
            ." and {$prefix}candidate_cursor_id between 0 and ({$phaseThrough})"
            ." and {$prefix}attempt_count between 0 and 3"
            ." and {$prefix}status in ('pending','running','sealed','blocked')"
            ." and (({$prefix}status = 'pending' and {$prefix}claim_token is null"
            ." and {$prefix}page_through_id is null and {$prefix}page_row_count is null"
            ." and {$prefix}completed_at is null"
            ." and ({$prefix}error_code is null or {$prefix}error_code = 'email_live_candidate_page_failed'))"
            ." or ({$prefix}status = 'running' and {$prefix}claim_token is not null"
            ." and length({$prefix}claim_token) = 64 and {$prefix}page_through_id is not null"
            ." and {$prefix}page_through_id >= {$prefix}candidate_cursor_id"
            ." and {$prefix}page_through_id <= ({$phaseThrough})"
            ." and {$prefix}page_row_count between 0 and 100"
            ." and {$prefix}attempt_count between 1 and 3 and {$prefix}last_attempt_at is not null"
            ." and {$prefix}completed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}status = 'sealed' and {$prefix}phase = 'sealed'"
            ." and {$prefix}claim_token is null and {$prefix}page_through_id is null"
            ." and {$prefix}page_row_count is null and {$prefix}completed_at is not null"
            ." and {$prefix}error_code is null)"
            ." or ({$prefix}status = 'blocked' and {$prefix}claim_token is null"
            ." and {$prefix}page_through_id is null and {$prefix}page_row_count is null"
            ." and {$prefix}attempt_count = 3 and {$prefix}completed_at is not null"
            ." and {$prefix}error_code in ('email_live_candidate_page_failed','email_live_append_failed','email_live_attempts_exhausted')))"
            ." and {$prefix}delivery_cursor_id >= 0"
            ." and {$prefix}delivery_count = {$prefix}delivery_appended_count"
            ." + {$prefix}delivery_suppressed_count"
            ." and {$prefix}delivery_attempt_count between 0 and 3"
            ." and {$prefix}delivery_summary_status in ('waiting','pending','running','sealed','blocked')"
            ." and (({$prefix}delivery_summary_status = 'waiting'"
            ." and {$prefix}status <> 'sealed' and {$prefix}delivery_through_id is null"
            ." and {$prefix}delivery_cursor_id = 0 and {$prefix}delivery_count = 0"
            ." and {$prefix}delivery_claim_token is null and {$prefix}delivery_page_through_id is null"
            ." and {$prefix}delivery_page_row_count is null and {$prefix}delivery_sealed_at is null"
            ." and {$prefix}delivery_error_code is null)"
            ." or ({$prefix}delivery_summary_status = 'pending' and {$prefix}status = 'sealed'"
            ." and {$prefix}delivery_through_id >= 0"
            ." and {$prefix}delivery_cursor_id <= {$prefix}delivery_through_id"
            ." and {$prefix}delivery_claim_token is null and {$prefix}delivery_page_through_id is null"
            ." and {$prefix}delivery_page_row_count is null and {$prefix}delivery_sealed_at is null"
            ." and ({$prefix}delivery_error_code is null"
            ." or {$prefix}delivery_error_code = 'email_live_delivery_summary_failed'))"
            ." or ({$prefix}delivery_summary_status = 'running' and {$prefix}status = 'sealed'"
            ." and {$prefix}delivery_through_id >= 0"
            ." and {$prefix}delivery_cursor_id <= {$prefix}delivery_through_id"
            ." and {$prefix}delivery_claim_token is not null and length({$prefix}delivery_claim_token) = 64"
            ." and {$prefix}delivery_page_through_id between {$prefix}delivery_cursor_id"
            ." and {$prefix}delivery_through_id and {$prefix}delivery_page_row_count between 0 and 100"
            ." and {$prefix}delivery_attempt_count between 1 and 3"
            ." and {$prefix}delivery_last_attempt_at is not null"
            ." and {$prefix}delivery_sealed_at is null and {$prefix}delivery_error_code is null)"
            ." or ({$prefix}delivery_summary_status = 'sealed' and {$prefix}status = 'sealed'"
            ." and {$prefix}delivery_through_id = {$prefix}delivery_cursor_id"
            ." and {$prefix}delivery_claim_token is null and {$prefix}delivery_page_through_id is null"
            ." and {$prefix}delivery_page_row_count is null and {$prefix}delivery_sealed_at is not null"
            ." and {$prefix}delivery_error_code is null)"
            ." or ({$prefix}delivery_summary_status = 'blocked' and {$prefix}status = 'sealed'"
            ." and {$prefix}delivery_through_id >= {$prefix}delivery_cursor_id"
            ." and {$prefix}delivery_claim_token is null and {$prefix}delivery_page_through_id is null"
            ." and {$prefix}delivery_page_row_count is null"
            ." and {$prefix}delivery_attempt_count = 3 and {$prefix}delivery_sealed_at is null"
            ." and {$prefix}delivery_error_code in"
            ." ('email_live_delivery_summary_failed','email_live_attempts_exhausted')))";
    }

    private function deliveryContract(string $prefix): string
    {
        $authorityNull = "{$prefix}authority_kind is null and {$prefix}authority_id is null"
            ." and {$prefix}authority_enable_generation is null"
            ." and {$prefix}content_authority_path_id is null"
            ." and {$prefix}frozen_content_authority_generation is null";
        $authorityPositive = "{$prefix}authority_kind in"
            ." ('owner','grant','delegation','break_glass','active_user')"
            ." and {$prefix}authority_id >= 1 and {$prefix}authority_enable_generation >= 1"
            ." and {$prefix}content_authority_path_id >= 1"
            ." and {$prefix}frozen_content_authority_generation >= 1";

        return "{$prefix}user_id >= 1 and {$prefix}frozen_user_authorization_epoch >= 1"
            ." and (({$authorityNull}) or ({$authorityPositive}))"
            ." and {$prefix}attempt_count between 0 and 3"
            ." and {$prefix}status in ('pending','running','appended','suppressed','blocked')"
            ." and (({$prefix}status = 'pending' and {$prefix}claim_token is null"
            ." and {$prefix}derived_change_id is null and {$prefix}derived_stream_id is null"
            ." and ({$authorityNull}) and {$prefix}completed_at is null"
            ." and ({$prefix}error_code is null or {$prefix}error_code = 'email_live_append_failed'))"
            ." or ({$prefix}status = 'running' and {$prefix}claim_token is not null"
            ." and length({$prefix}claim_token) = 64 and {$prefix}attempt_count between 1 and 3"
            ." and {$prefix}last_attempt_at is not null and {$prefix}derived_change_id is null"
            ." and {$prefix}derived_stream_id is null and ({$authorityNull})"
            ." and {$prefix}completed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}status = 'appended' and {$prefix}claim_token is null"
            ." and {$prefix}derived_change_id is not null and {$prefix}derived_stream_id is not null"
            ." and ({$authorityPositive}) and {$prefix}completed_at is not null"
            ." and {$prefix}error_code is null)"
            ." or ({$prefix}status = 'suppressed' and {$prefix}claim_token is null"
            ." and {$prefix}derived_change_id is null and {$prefix}derived_stream_id is null"
            ." and ({$authorityNull}) and {$prefix}completed_at is not null"
            ." and {$prefix}error_code in ('email_live_currently_unauthorized','email_live_source_path_ineligible','email_live_duplicate_candidate'))"
            ." or ({$prefix}status = 'blocked' and {$prefix}claim_token is null"
            ." and {$prefix}derived_change_id is null and {$prefix}derived_stream_id is null"
            ." and ({$authorityNull}) and {$prefix}attempt_count = 3"
            ." and {$prefix}completed_at is not null"
            ." and {$prefix}error_code in ('email_live_append_failed','email_live_attempts_exhausted')))";
    }

    private function globalAuthorityContract(string $prefix): string
    {
        return "{$prefix}id = 1 and {$prefix}active_user_generation >= 1"
            ." and {$prefix}content_audience_generation >= 1"
            ." and {$prefix}content_ability_generation >= 1"
            ." and {$prefix}authorization_generation >= 1";
    }

    private function accountAuthorityContract(string $prefix): string
    {
        return "{$prefix}email_account_id >= 1 and {$prefix}audience_generation >= 1"
            ." and ({$prefix}owner_user_id is null or {$prefix}owner_user_id >= 1)"
            ." and {$prefix}owner_enable_generation between 1 and {$prefix}audience_generation";
    }

    private function userAccessContract(string $prefix): string
    {
        return "{$prefix}user_id >= 1 and {$prefix}authorization_epoch >= 1"
            ." and {$prefix}content_ability_enable_generation >= 1"
            ." and {$prefix}global_authorization_generation_seen >= 1"
            ." and {$prefix}delegation_through_id >= 0 and {$prefix}break_glass_through_id >= 0"
            ." and {$prefix}recompute_cursor_id >= 0 and {$prefix}attempt_count between 0 and 3"
            ." and {$prefix}recompute_status in ('sealed','pending','running','blocked')"
            ." and (({$prefix}recompute_status = 'sealed' and {$prefix}recompute_phase is null"
            ." and {$prefix}claim_token is null and {$prefix}page_through_id is null"
            ." and {$prefix}page_row_count is null and {$prefix}recompute_boundary_at is null"
            ." and {$prefix}completed_at is not null and {$prefix}error_code is null)"
            ." or ({$prefix}recompute_status = 'pending'"
            ." and {$prefix}recompute_phase in ('delegations','break_glass')"
            ." and {$prefix}claim_token is null and {$prefix}page_through_id is null"
            ." and {$prefix}page_row_count is null and {$prefix}recompute_boundary_at is not null"
            ." and {$prefix}completed_at is null"
            ." and ({$prefix}error_code is null or {$prefix}error_code = 'email_live_access_recompute_failed'))"
            ." or ({$prefix}recompute_status = 'running'"
            ." and {$prefix}recompute_phase in ('delegations','break_glass')"
            ." and {$prefix}claim_token is not null and length({$prefix}claim_token) = 64"
            ." and {$prefix}page_through_id is not null"
            ." and {$prefix}page_through_id >= {$prefix}recompute_cursor_id"
            ." and {$prefix}page_through_id <= case {$prefix}recompute_phase"
            ." when 'delegations' then {$prefix}delegation_through_id"
            ." else {$prefix}break_glass_through_id end"
            ." and {$prefix}page_row_count between 0 and 100"
            ." and {$prefix}attempt_count between 1 and 3 and {$prefix}last_attempt_at is not null"
            ." and {$prefix}recompute_boundary_at is not null"
            ." and {$prefix}completed_at is null and {$prefix}error_code is null)"
            ." or ({$prefix}recompute_status = 'blocked' and {$prefix}claim_token is null"
            ." and {$prefix}page_through_id is null and {$prefix}page_row_count is null"
            ." and {$prefix}attempt_count = 3 and {$prefix}completed_at is not null"
            ." and {$prefix}error_code in ('email_live_access_recompute_failed','email_live_attempts_exhausted')))";
    }

    private function contentAuthorityPathContract(string $prefix): string
    {
        return "{$prefix}user_id >= 1 and {$prefix}permission_id >= 1"
            ." and {$prefix}path_type in ('direct_permission','role_membership')"
            ." and (({$prefix}path_type = 'direct_permission' and {$prefix}direct_slot = 1"
            ." and {$prefix}role_id is null)"
            ." or ({$prefix}path_type = 'role_membership' and {$prefix}direct_slot is null"
            ." and {$prefix}role_id >= 1))"
            ." and {$prefix}enabled in (0,1) and {$prefix}enable_generation >= 1"
            ." and (({$prefix}enabled = 1 and {$prefix}enabled_at is not null"
            ." and {$prefix}disabled_at is null)"
            ." or ({$prefix}enabled = 0 and {$prefix}enabled_at is not null"
            ." and {$prefix}disabled_at is not null))";
    }

    private function streamInsertContract(): string
    {
        return $this->streamContract('NEW.')
            .' and NEW.current_version = 0 and NEW.oldest_retained_version = 1'
            .' and NEW.acknowledged_version = 0 and NEW.acknowledged_at is null'
            .' and NEW.last_changed_at is null';
    }

    private function streamUpdateContract(): string
    {
        $immutable = $this->sameColumns([
            'id',
            'stream_type',
            'email_account_id',
            'user_id',
            'global_slot',
            'created_at',
        ]);
        $sameCurrent = $this->sameColumn('current_version');
        $sameChangedAt = $this->sameColumn('last_changed_at');
        $sameAcknowledged = $this->sameColumn('acknowledged_version');
        $sameAcknowledgedAt = $this->sameColumn('acknowledged_at');

        return $this->streamContract('NEW.')." and ({$immutable})"
            ." and (({$sameCurrent} and {$sameChangedAt})"
            .' or (NEW.current_version = OLD.current_version + 1'
            .' and NEW.last_changed_at is not null'
            ." and not ({$sameChangedAt})))"
            .' and NEW.oldest_retained_version >= OLD.oldest_retained_version'
            ." and (({$sameAcknowledged} and {$sameAcknowledgedAt})"
            .' or (NEW.acknowledged_version > OLD.acknowledged_version'
            .' and NEW.acknowledged_at is not null'
            ." and not ({$sameAcknowledgedAt})))";
    }

    private function changeInsertContract(): string
    {
        return $this->changeContract('NEW.')." and ({$this->changeLinkContract('NEW.')})"
            .' and exists(select 1 from '.self::STREAMS.' as insertion_stream'
            .' where insertion_stream.id = NEW.stream_id'
            .' and insertion_stream.current_version = NEW.version)'
            ." and NEW.publication_status = 'pending' and NEW.attempt_count = 0"
            .' and NEW.last_attempt_at is null and NEW.next_attempt_at is null'
            .' and NEW.published_at is null and NEW.sealed_at is null'
            .' and NEW.retention_ready_at is null and NEW.error_code is null';
    }

    private function changeUpdateContract(): string
    {
        $payload = $this->sameColumns([
            'id',
            'stream_id',
            'version',
            'email_account_id',
            'idempotency_key',
            'change_types_json',
            'conversation_ids_json',
            'placement_ids_json',
            'conversation_id_count',
            'placement_id_count',
            'truncated',
            'available_at',
            'created_at',
        ]);
        $terminal = $this->sameColumns([
            'publication_status',
            'claim_token',
            'attempt_count',
            'last_attempt_at',
            'next_attempt_at',
            'published_at',
            'sealed_at',
            'retention_ready_at',
            'compact_delivery_count',
            'compact_appended_count',
            'compact_suppressed_count',
            'error_code',
            'updated_at',
        ]);
        $retry = "OLD.publication_status = 'running' and NEW.publication_status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count';
        $claim = "OLD.publication_status = 'pending' and NEW.publication_status = 'running'"
            .' and NEW.attempt_count = OLD.attempt_count + 1';
        $published = "OLD.publication_status = 'running' and NEW.publication_status = 'published'"
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and exists(select 1 from '.self::STREAMS.' as publication_stream'
            .' where publication_stream.id = NEW.stream_id'
            ." and publication_stream.stream_type = 'user')";
        $sealed = "((OLD.publication_status = 'published' or OLD.publication_status = 'running')"
            ." and NEW.publication_status = 'sealed'"
            .' and NEW.attempt_count = OLD.attempt_count'
            ." and ({$this->changeRetentionAttestation('NEW.')}))";
        $blocked = "OLD.publication_status = 'running' and NEW.publication_status = 'blocked'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.attempt_count = 3';

        return $this->changeContract('NEW.')." and ({$this->changeLinkContract('NEW.')})"
            ." and ({$payload}) and ((OLD.publication_status in ('sealed','blocked')"
            ." and ({$terminal})) or ({$claim}) or ({$retry}) or ({$published})"
            ." or ({$sealed}) or ({$blocked}))";
    }

    private function changeRetentionAttestation(string $prefix): string
    {
        return "{$prefix}retention_ready_at is not null and ("
            .'(exists(select 1 from '.self::STREAMS.' as retained_user_stream'
            ." where retained_user_stream.id = {$prefix}stream_id"
            ." and retained_user_stream.stream_type = 'user'"
            ." and retained_user_stream.acknowledged_version >= {$prefix}version)"
            ." and {$prefix}compact_delivery_count = 0"
            .' and not exists(select 1 from '.self::PUBLICATIONS
            ." where source_change_id = {$prefix}id))"
            .' or exists(select 1 from '.self::PUBLICATIONS.' as retained_publication'
            ." where retained_publication.source_change_id = {$prefix}id"
            ." and retained_publication.delivery_summary_status = 'sealed'"
            ." and retained_publication.delivery_count = {$prefix}compact_delivery_count"
            ." and retained_publication.delivery_appended_count = {$prefix}compact_appended_count"
            ." and retained_publication.delivery_suppressed_count = {$prefix}compact_suppressed_count))";
    }

    private function publicationInsertContract(): string
    {
        return $this->publicationContract('NEW.')
            ." and ({$this->publicationLinkContract('NEW.')})"
            ." and NEW.status = 'pending' and NEW.candidate_cursor_id = 0"
            .' and NEW.attempt_count = 0 and NEW.page_count = 0'
            .' and NEW.last_attempt_at is null and NEW.next_attempt_at is null'
            .' and NEW.completed_at is null and NEW.error_code is null'
            ." and NEW.delivery_summary_status = 'waiting'"
            .' and NEW.delivery_attempt_count = 0 and NEW.delivery_page_count = 0'
            .' and ((NEW.source_stream_type = \'account\' and NEW.phase = \'owner\')'
            .' or (NEW.source_stream_type = \'global\' and NEW.phase = \'active_users\'))'
            ." and ({$this->publicationFrozenSnapshotContract('NEW.')})";
    }

    private function publicationUpdateContract(): string
    {
        $frozen = $this->sameColumns([
            'id',
            'source_change_id',
            'source_stream_id',
            'source_stream_type',
            'email_account_id',
            'source_at',
            'frozen_owner_user_id',
            'account_audience_generation',
            'global_active_user_generation',
            'global_content_audience_generation',
            'global_content_ability_generation',
            'grant_through_id',
            'delegation_through_id',
            'break_glass_through_id',
            'active_user_through_id',
            'created_at',
        ]);
        $candidateTerminal = $this->sameColumns([
            'phase',
            'candidate_cursor_id',
            'status',
            'claim_token',
            'page_through_id',
            'page_row_count',
            'attempt_count',
            'page_count',
            'last_attempt_at',
            'next_attempt_at',
            'completed_at',
            'error_code',
        ]);
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.candidate_cursor_id'
            .' and NEW.attempt_count = OLD.attempt_count + 1'
            .' and NEW.page_count = OLD.page_count';
        $pageCommit = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and ((NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.page_through_id)'
            ." or ({$this->publicationNextPhaseContract()}))";
        $seal = "OLD.status = 'running' and NEW.status = 'sealed' and NEW.phase = 'sealed'"
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            ." and ({$this->publicationLastPhaseCompleteContract()})";
        $block = "OLD.status = 'running' and NEW.status = 'blocked'"
            .' and NEW.phase = OLD.phase and NEW.candidate_cursor_id = OLD.candidate_cursor_id'
            .' and NEW.attempt_count = OLD.attempt_count and NEW.attempt_count = 3'
            .' and NEW.page_count = OLD.page_count';
        $candidate = "((OLD.status = 'sealed' and ({$candidateTerminal}))"
            ." or (OLD.status = 'blocked' and ({$candidateTerminal})"
            .' and '.$this->sameColumns($this->publicationSummaryColumns(), includeUpdatedAt: true).')'
            ." or ({$claim}) or ({$pageCommit}) or ({$seal}) or ({$block}))";

        return $this->publicationContract('NEW.')
            ." and ({$this->publicationLinkContract('NEW.')}) and ({$frozen})"
            ." and ({$candidate}) and ({$this->publicationSummaryTransitionContract()})";
    }

    private function publicationFrozenSnapshotContract(string $prefix): string
    {
        return 'exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            ." and global_authority.content_audience_generation = {$prefix}global_content_audience_generation"
            ." and global_authority.content_ability_generation = {$prefix}global_content_ability_generation"
            ." and (({$prefix}source_stream_type = 'account'"
            .' and exists(select 1 from '.self::ACCOUNT_AUTHORITY.' as account_authority'
            ." where account_authority.email_account_id = {$prefix}email_account_id"
            ." and account_authority.owner_user_id is {$prefix}frozen_owner_user_id"
            ." and account_authority.audience_generation = {$prefix}account_audience_generation)"
            ." and {$prefix}grant_through_id = coalesce((select max(candidate.id)"
            .' from email_account_user_grants as candidate'
            ." where candidate.email_account_id = {$prefix}email_account_id), 0)"
            ." and {$prefix}delegation_through_id = coalesce((select max(candidate.id)"
            .' from email_mailbox_delegations as candidate'
            ." where candidate.email_account_id = {$prefix}email_account_id), 0)"
            ." and {$prefix}break_glass_through_id = coalesce((select max(candidate.id)"
            .' from email_break_glass_accesses as candidate'
            ." where candidate.email_account_id = {$prefix}email_account_id), 0)"
            ." and {$prefix}active_user_through_id = 0)"
            ." or ({$prefix}source_stream_type = 'global'"
            ." and {$prefix}global_active_user_generation = global_authority.active_user_generation"
            ." and {$prefix}active_user_through_id = coalesce((select max(candidate.id)"
            .' from user_management as candidate), 0)'
            ." and {$prefix}grant_through_id = 0 and {$prefix}delegation_through_id = 0"
            ." and {$prefix}break_glass_through_id = 0)))";
    }

    private function publicationNextPhaseContract(): string
    {
        return '(OLD.source_stream_type = \'account\' and OLD.page_through_id = case OLD.phase'
            .' when \'owner\' then case when OLD.frozen_owner_user_id is null then 0 else 1 end'
            .' when \'grants\' then OLD.grant_through_id'
            .' when \'delegations\' then OLD.delegation_through_id else -1 end'
            .' and NEW.phase = case OLD.phase when \'owner\' then \'grants\''
            .' when \'grants\' then \'delegations\''
            .' when \'delegations\' then \'break_glass\' end'
            .' and NEW.candidate_cursor_id = 0)';
    }

    private function publicationLastPhaseCompleteContract(): string
    {
        return "((OLD.source_stream_type = 'account' and OLD.phase = 'break_glass'"
            .' and OLD.page_through_id = OLD.break_glass_through_id)'
            ." or (OLD.source_stream_type = 'global' and OLD.phase = 'active_users'"
            .' and OLD.page_through_id = OLD.active_user_through_id))'
            .' and NEW.delivery_summary_status = \'pending\''
            .' and NEW.delivery_through_id = coalesce((select max(delivery.id)'
            .' from '.self::DELIVERIES.' as delivery'
            .' where delivery.publication_id = NEW.id), 0)';
    }

    /** @return list<string> */
    private function publicationSummaryColumns(): array
    {
        return [
            'delivery_summary_status',
            'delivery_through_id',
            'delivery_cursor_id',
            'delivery_count',
            'delivery_appended_count',
            'delivery_suppressed_count',
            'delivery_claim_token',
            'delivery_page_through_id',
            'delivery_page_row_count',
            'delivery_attempt_count',
            'delivery_page_count',
            'delivery_last_attempt_at',
            'delivery_next_attempt_at',
            'delivery_sealed_at',
            'delivery_error_code',
        ];
    }

    private function publicationSummaryTransitionContract(): string
    {
        $sameSummary = $this->sameColumns($this->publicationSummaryColumns());
        $summaryClaim = "OLD.delivery_summary_status = 'pending'"
            ." and NEW.delivery_summary_status = 'running'"
            .' and NEW.delivery_cursor_id = OLD.delivery_cursor_id'
            .' and NEW.delivery_count = OLD.delivery_count'
            .' and NEW.delivery_appended_count = OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count = OLD.delivery_suppressed_count'
            .' and NEW.delivery_attempt_count = OLD.delivery_attempt_count + 1'
            .' and NEW.delivery_page_count = OLD.delivery_page_count';
        $summaryCommit = "OLD.delivery_summary_status = 'running'"
            ." and NEW.delivery_summary_status in ('pending','sealed')"
            .' and NEW.delivery_cursor_id = OLD.delivery_page_through_id'
            .' and NEW.delivery_count = OLD.delivery_count + OLD.delivery_page_row_count'
            .' and NEW.delivery_appended_count >= OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count >= OLD.delivery_suppressed_count'
            .' and NEW.delivery_attempt_count = OLD.delivery_attempt_count'
            .' and NEW.delivery_page_count = OLD.delivery_page_count + 1'
            ." and (NEW.delivery_summary_status <> 'sealed'"
            .' or NEW.delivery_cursor_id = OLD.delivery_through_id)';
        $summaryBlock = "OLD.delivery_summary_status = 'running'"
            ." and NEW.delivery_summary_status = 'blocked'"
            .' and NEW.delivery_cursor_id = OLD.delivery_cursor_id'
            .' and NEW.delivery_count = OLD.delivery_count'
            .' and NEW.delivery_appended_count = OLD.delivery_appended_count'
            .' and NEW.delivery_suppressed_count = OLD.delivery_suppressed_count'
            .' and NEW.delivery_attempt_count = OLD.delivery_attempt_count'
            .' and NEW.delivery_attempt_count = 3'
            .' and NEW.delivery_page_count = OLD.delivery_page_count';
        $startSummary = "OLD.delivery_summary_status = 'waiting'"
            ." and NEW.delivery_summary_status = 'pending' and NEW.status = 'sealed'"
            .' and NEW.delivery_through_id = coalesce((select max(delivery.id)'
            .' from '.self::DELIVERIES.' as delivery'
            .' where delivery.publication_id = NEW.id), 0)';

        return "((OLD.delivery_summary_status = 'waiting' and NEW.status <> 'sealed'"
            ." and ({$sameSummary})) or ({$startSummary}) or ({$summaryClaim})"
            ." or ({$summaryCommit}) or ({$summaryBlock})"
            ." or (OLD.delivery_summary_status in ('sealed','blocked') and ({$sameSummary})))";
    }

    private function deliveryInsertContract(): string
    {
        return $this->deliveryContract('NEW.')
            ." and ({$this->deliveryLinkContract('NEW.')})"
            ." and NEW.status = 'pending' and NEW.attempt_count = 0"
            .' and NEW.last_attempt_at is null and NEW.next_attempt_at is null'
            .' and NEW.completed_at is null and NEW.error_code is null'
            .' and exists(select 1 from '.self::USER_ACCESS.' as recipient_access'
            .' where recipient_access.user_id = NEW.user_id'
            .' and recipient_access.authorization_epoch = NEW.frozen_user_authorization_epoch)';
    }

    private function deliveryUpdateContract(): string
    {
        $immutable = $this->sameColumns([
            'id',
            'publication_id',
            'source_change_id',
            'user_id',
            'frozen_user_authorization_epoch',
            'created_at',
        ]);
        $terminal = $this->sameColumns([
            'authority_kind',
            'authority_id',
            'authority_enable_generation',
            'content_authority_path_id',
            'frozen_content_authority_generation',
            'derived_change_id',
            'derived_stream_id',
            'status',
            'claim_token',
            'attempt_count',
            'last_attempt_at',
            'next_attempt_at',
            'completed_at',
            'error_code',
            'updated_at',
        ]);
        $claim = "OLD.status = 'pending' and NEW.status = 'running'"
            .' and NEW.attempt_count = OLD.attempt_count + 1';
        $retry = "OLD.status = 'running' and NEW.status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count';
        $appended = "OLD.status = 'running' and NEW.status = 'appended'"
            .' and NEW.attempt_count = OLD.attempt_count'
            ." and ({$this->deliveryAppendAuthorityContract('NEW.')})";
        $suppressed = "OLD.status = 'running' and NEW.status = 'suppressed'"
            .' and NEW.attempt_count = OLD.attempt_count';
        $blocked = "OLD.status = 'running' and NEW.status = 'blocked'"
            .' and NEW.attempt_count = OLD.attempt_count and NEW.attempt_count = 3';

        return $this->deliveryContract('NEW.')
            ." and ({$this->deliveryLinkContract('NEW.')}) and ({$immutable})"
            ." and ((OLD.status in ('appended','suppressed','blocked') and ({$terminal}))"
            ." or ({$claim}) or ({$retry}) or ({$appended})"
            ." or ({$suppressed}) or ({$blocked}))";
    }

    private function globalAuthorityInsertContract(): string
    {
        return $this->globalAuthorityContract('NEW.')
            .' and NEW.active_user_generation = 1'
            .' and NEW.content_audience_generation = 1'
            .' and NEW.content_ability_generation = 1'
            .' and NEW.authorization_generation = 1';
    }

    private function globalAuthorityUpdateContract(): string
    {
        return $this->globalAuthorityContract('NEW.')
            .' and '.$this->sameColumns(['id', 'created_at'])
            .' and NEW.active_user_generation between OLD.active_user_generation'
            .' and OLD.active_user_generation + 1'
            .' and NEW.content_audience_generation between OLD.content_audience_generation'
            .' and OLD.content_audience_generation + 1'
            .' and NEW.content_ability_generation between OLD.content_ability_generation'
            .' and OLD.content_ability_generation + 1'
            .' and NEW.authorization_generation between OLD.authorization_generation'
            .' and OLD.authorization_generation + 1';
    }

    private function accountAuthorityInsertContract(): string
    {
        return $this->accountAuthorityContract('NEW.')
            .' and NEW.audience_generation = 1 and NEW.owner_enable_generation = 1'
            .' and exists(select 1 from email_accounts as source_account'
            .' where source_account.id = NEW.email_account_id'
            .' and ((source_account.owner_id = NEW.owner_user_id)'
            .' or (source_account.owner_id is null and NEW.owner_user_id is null))'
            .' and source_account.email_live_owner_enable_generation = NEW.owner_enable_generation)';
    }

    private function accountAuthorityUpdateContract(): string
    {
        $sameOwner = $this->sameColumn('owner_user_id');
        $sameOwnerGeneration = $this->sameColumn('owner_enable_generation');

        return $this->accountAuthorityContract('NEW.')
            .' and '.$this->sameColumns(['id', 'email_account_id', 'created_at'])
            .' and NEW.audience_generation between OLD.audience_generation'
            .' and OLD.audience_generation + 1'
            ." and (({$sameOwner} and {$sameOwnerGeneration})"
            .' or (not ('.$sameOwner.')'
            .' and NEW.owner_enable_generation = OLD.owner_enable_generation + 1'
            .' and NEW.audience_generation = OLD.audience_generation + 1)'
            .' or ('.$sameOwner
            .' and NEW.owner_enable_generation = OLD.owner_enable_generation + 1'
            .' and NEW.audience_generation = OLD.audience_generation + 1))';
    }

    private function userAccessInsertContract(): string
    {
        return $this->userAccessContract('NEW.')
            .' and NEW.authorization_epoch = 1'
            .' and NEW.content_ability_enable_generation = 1'
            .' and NEW.global_authorization_generation_seen = 1'
            ." and NEW.recompute_status = 'pending' and NEW.recompute_phase = 'delegations'"
            .' and NEW.recompute_cursor_id = 0 and NEW.attempt_count = 0 and NEW.page_count = 0'
            .' and NEW.last_attempt_at is null and NEW.completed_at is null and NEW.error_code is null'
            ." and ({$this->userAccessFrozenHighWaterContract('NEW.')})";
    }

    private function userAccessUpdateContract(): string
    {
        $identity = $this->sameColumns(['id', 'user_id', 'created_at']);
        $generation = 'NEW.authorization_epoch between OLD.authorization_epoch'
            .' and OLD.authorization_epoch + 1'
            .' and NEW.content_ability_enable_generation between OLD.content_ability_enable_generation'
            .' and OLD.content_ability_enable_generation + 1'
            .' and NEW.global_authorization_generation_seen >= OLD.global_authorization_generation_seen'
            .' and exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            .' and NEW.global_authorization_generation_seen <= global_authority.authorization_generation'
            .' and NEW.content_ability_enable_generation <= global_authority.content_ability_generation)';
        $claim = "OLD.recompute_status = 'pending' and NEW.recompute_status = 'running'"
            .' and NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.recompute_cursor_id'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count + 1'
            .' and NEW.page_count = OLD.page_count';
        $commit = "OLD.recompute_status = 'running' and NEW.recompute_status = 'pending'"
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and ((NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.page_through_id)'
            ." or (OLD.recompute_phase = 'delegations'"
            .' and OLD.page_through_id = OLD.delegation_through_id'
            ." and NEW.recompute_phase = 'break_glass' and NEW.recompute_cursor_id = 0))";
        $seal = "OLD.recompute_status = 'running' and NEW.recompute_status = 'sealed'"
            ." and OLD.recompute_phase = 'break_glass'"
            .' and OLD.page_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count'
            .' and NEW.page_count = OLD.page_count + 1'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id';
        $block = "OLD.recompute_status = 'running' and NEW.recompute_status = 'blocked'"
            .' and NEW.recompute_phase = OLD.recompute_phase'
            .' and NEW.recompute_cursor_id = OLD.recompute_cursor_id'
            .' and NEW.delegation_through_id = OLD.delegation_through_id'
            .' and NEW.break_glass_through_id = OLD.break_glass_through_id'
            .' and NEW.attempt_count = OLD.attempt_count and NEW.attempt_count = 3'
            .' and NEW.page_count = OLD.page_count';
        $reset = "OLD.recompute_status in ('sealed','pending','running')"
            ." and NEW.recompute_status = 'pending' and NEW.recompute_phase = 'delegations'"
            .' and NEW.recompute_cursor_id = 0 and NEW.attempt_count = 0 and NEW.page_count = 0'
            .' and (NEW.authorization_epoch = OLD.authorization_epoch + 1'
            .' or NEW.content_ability_enable_generation = OLD.content_ability_enable_generation + 1)'
            ." and ({$this->userAccessFrozenHighWaterContract('NEW.')})";
        $blocked = "OLD.recompute_status = 'blocked'"
            .' and '.$this->sameColumns([
                'recompute_status',
                'recompute_phase',
                'delegation_through_id',
                'break_glass_through_id',
                'recompute_cursor_id',
                'recompute_boundary_at',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'attempt_count',
                'page_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
                'updated_at',
            ]);
        $sealedNoop = "OLD.recompute_status = 'sealed' and NEW.recompute_status = 'sealed'"
            .' and '.$this->sameColumns([
                'authorization_epoch',
                'content_ability_enable_generation',
                'global_authorization_generation_seen',
                'next_boundary_at',
                'last_bounded_refresh_at',
                'recompute_status',
                'recompute_phase',
                'delegation_through_id',
                'break_glass_through_id',
                'recompute_cursor_id',
                'recompute_boundary_at',
                'claim_token',
                'page_through_id',
                'page_row_count',
                'attempt_count',
                'page_count',
                'last_attempt_at',
                'completed_at',
                'error_code',
                'updated_at',
            ]);

        return $this->userAccessContract('NEW.')." and ({$identity}) and ({$generation})"
            ." and (({$claim}) or ({$commit}) or ({$seal}) or ({$block})"
            ." or ({$reset}) or ({$blocked}) or ({$sealedNoop}))";
    }

    private function userAccessFrozenHighWaterContract(string $prefix): string
    {
        return "{$prefix}delegation_through_id = coalesce((select max(candidate.id)"
            .' from email_mailbox_delegations as candidate'
            ." where candidate.delegate_id = {$prefix}user_id), 0)"
            ." and {$prefix}break_glass_through_id = coalesce((select max(candidate.id)"
            .' from email_break_glass_accesses as candidate'
            ." where candidate.actor_id = {$prefix}user_id), 0)";
    }

    private function contentPathInsertContract(): string
    {
        return $this->contentAuthorityPathContract('NEW.')
            .' and NEW.enabled = 1'
            .' and exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            .' and global_authority.content_ability_generation = NEW.enable_generation)'
            .' and exists(select 1 from permissions as content_permission'
            .' where content_permission.id = NEW.permission_id'
            ." and content_permission.name = 'email.inbox_view')";
    }

    private function contentPathUpdateContract(): string
    {
        $identity = $this->sameColumns([
            'id',
            'user_id',
            'path_type',
            'permission_id',
            'role_id',
            'direct_slot',
            'created_at',
        ]);
        $stable = $this->sameColumns([
            'enabled',
            'enable_generation',
            'enabled_at',
            'disabled_at',
            'updated_at',
        ]);
        $disable = 'OLD.enabled = 1 and NEW.enabled = 0'
            .' and NEW.enable_generation = OLD.enable_generation'
            .' and NEW.enabled_at = OLD.enabled_at and NEW.disabled_at is not null'
            .' and exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            .' and global_authority.content_ability_generation > OLD.enable_generation)';
        $enable = 'OLD.enabled = 0 and NEW.enabled = 1'
            .' and NEW.enable_generation > OLD.enable_generation'
            .' and not ('.$this->sameColumn('enabled_at').')'
            .' and exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            .' and global_authority.content_ability_generation = NEW.enable_generation)';

        return $this->contentAuthorityPathContract('NEW.')." and ({$identity})"
            ." and (({$stable}) or ({$disable}) or ({$enable}))";
    }

    private function changeDeleteContract(string $prefix): string
    {
        return "{$prefix}publication_status = 'sealed' and {$prefix}retention_ready_at is not null"
            ." and {$prefix}claim_token is null"
            .' and not exists(select 1 from '.self::PUBLICATIONS
            ." where source_change_id = {$prefix}id)"
            .' and exists(select 1 from '.self::STREAMS.' as retention_stream'
            ." where retention_stream.id = {$prefix}stream_id"
            ." and {$prefix}version < retention_stream.oldest_retained_version"
            ." and (retention_stream.stream_type <> 'user'"
            ." or {$prefix}version <= retention_stream.acknowledged_version))";
    }

    private function publicationDeleteContract(string $prefix): string
    {
        return "{$prefix}status = 'sealed' and {$prefix}delivery_summary_status = 'sealed'"
            ." and {$prefix}delivery_cursor_id = {$prefix}delivery_through_id"
            .' and not exists(select 1 from '.self::DELIVERIES
            ." where publication_id = {$prefix}id)"
            .' and exists(select 1 from '.self::CHANGES.' as retained_change'
            ." where retained_change.id = {$prefix}source_change_id"
            .' and retained_change.retention_ready_at is not null)';
    }

    private function deliveryDeleteContract(string $prefix): string
    {
        return "{$prefix}status in ('appended','suppressed')"
            .' and exists(select 1 from '.self::PUBLICATIONS.' as retained_publication'
            .' join '.self::CHANGES.' as retained_change'
            .' on retained_change.id = retained_publication.source_change_id'
            ." where retained_publication.id = {$prefix}publication_id"
            ." and retained_publication.source_change_id = {$prefix}source_change_id"
            ." and retained_publication.delivery_summary_status = 'sealed'"
            ." and {$prefix}id <= retained_publication.delivery_through_id"
            .' and retained_change.retention_ready_at is not null)';
    }

    private function replaceBaseAuthorityGuards(): void
    {
        $globalActive = 'exists(select 1 from '.self::GLOBAL_AUTHORITY.' as global_authority'
            .' where global_authority.id = 1'
            .' and global_authority.active_user_generation = NEW.email_live_enable_generation)';
        $userRelevantSame = $this->sameColumns(['status', 'is_system_actor']);
        $userAccessPending = 'exists(select 1 from '.self::USER_ACCESS.' as access_state'
            .' where access_state.user_id = NEW.id'
            ." and access_state.recompute_status = 'pending')";
        $this->replaceContractGuard(
            'user_management',
            'em_live_user_generation_guard',
            'NEW.email_live_enable_generation >= 1 and '.$globalActive,
            'NEW.email_live_enable_generation >= OLD.email_live_enable_generation'
                ." and (({$userRelevantSame}"
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation)'
                .' or (not ('.$userRelevantSame.')'
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation + 1'
                .' and '.$globalActive.' and '.$userAccessPending.'))',
        );

        $sameOwner = $this->sameColumn('owner_id');
        $ownerState = 'exists(select 1 from '.self::ACCOUNT_AUTHORITY.' as account_authority'
            .' where account_authority.email_account_id = NEW.id'
            .' and ((account_authority.owner_user_id = NEW.owner_id)'
            .' or (account_authority.owner_user_id is null and NEW.owner_id is null))'
            .' and account_authority.owner_enable_generation = NEW.email_live_owner_enable_generation'
            .' and account_authority.audience_generation >= account_authority.owner_enable_generation)';
        $this->replaceContractGuard(
            'email_accounts',
            'em_live_account_owner_generation_guard',
            'NEW.email_live_owner_enable_generation >= 1',
            'NEW.email_live_owner_enable_generation >= OLD.email_live_owner_enable_generation'
                ." and (({$sameOwner}"
                .' and NEW.email_live_owner_enable_generation = OLD.email_live_owner_enable_generation)'
                .' or (not ('.$sameOwner.')'
                .' and NEW.email_live_owner_enable_generation = OLD.email_live_owner_enable_generation + 1'
                .' and '.$ownerState.'))',
        );

        $grantIdentity = $this->sameColumns(['id', 'email_account_id', 'user_id', 'created_at']);
        $grantRelevant = $this->sameColumns(['can_view', 'granted_at']);
        $this->replaceContractGuard(
            'email_account_user_grants',
            'em_live_grant_generation_guard',
            'NEW.email_live_enable_generation >= 1 and '.$this->accountAudienceIsCurrent('NEW.email_account_id'),
            "({$grantIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                ." and (({$grantRelevant}"
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation)'
                .' or (not ('.$grantRelevant.')'
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation + 1'
                .' and '.$this->accountAudienceIsCurrent('NEW.email_account_id')
                .' and '.$this->userRecomputeIsPending('NEW.user_id').'))',
        );

        $delegationIdentity = $this->sameColumns(['id', 'email_account_id', 'owner_id', 'delegate_id', 'created_at']);
        $delegationRelevant = $this->sameColumns(['can_view', 'starts_at', 'expires_at', 'revoked_at']);
        $this->replaceContractGuard(
            'email_mailbox_delegations',
            'em_live_delegate_generation_guard',
            'NEW.email_live_enable_generation >= 1 and '.$this->accountAudienceIsCurrent('NEW.email_account_id'),
            "({$delegationIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                .' and ('.$this->oneTimeTimestamp('email_live_start_invalidated_at').')'
                .' and ('.$this->oneTimeTimestamp('email_live_expiry_invalidated_at').')'
                ." and (({$delegationRelevant}"
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation)'
                .' or (not ('.$delegationRelevant.')'
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation + 1'
                .' and '.$this->accountAudienceIsCurrent('NEW.email_account_id')
                .' and '.$this->userRecomputeIsPending('NEW.delegate_id').'))',
        );

        $breakIdentity = $this->sameColumns(['id', 'email_account_id', 'actor_id', 'created_at']);
        $breakRelevant = $this->sameColumns(['can_view_content', 'starts_at', 'expires_at', 'revoked_at']);
        $this->replaceContractGuard(
            'email_break_glass_accesses',
            'em_live_break_generation_guard',
            'NEW.email_live_enable_generation >= 1 and '.$this->accountAudienceIsCurrent('NEW.email_account_id'),
            "({$breakIdentity}) and NEW.email_live_enable_generation >= OLD.email_live_enable_generation"
                .' and ('.$this->oneTimeTimestamp('email_live_start_invalidated_at').')'
                .' and ('.$this->oneTimeTimestamp('email_live_expiry_invalidated_at').')'
                ." and (({$breakRelevant}"
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation)'
                .' or (not ('.$breakRelevant.')'
                .' and NEW.email_live_enable_generation = OLD.email_live_enable_generation + 1'
                .' and '.$this->accountAudienceIsCurrent('NEW.email_account_id')
                .' and '.$this->userRecomputeIsPending('NEW.actor_id').'))',
        );
    }

    private function accountAudienceIsCurrent(string $account): string
    {
        return 'exists(select 1 from '.self::ACCOUNT_AUTHORITY.' as account_authority'
            ." where account_authority.email_account_id = {$account}"
            .' and account_authority.audience_generation = NEW.email_live_enable_generation)';
    }

    private function userRecomputeIsPending(string $user): string
    {
        return 'exists(select 1 from '.self::USER_ACCESS.' as access_state'
            ." where access_state.user_id = {$user}"
            ." and access_state.recompute_status = 'pending')";
    }

    private function oneTimeTimestamp(string $column): string
    {
        return "(OLD.{$column} is null or NEW.{$column} = OLD.{$column})";
    }

    /** @param list<string> $columns */
    private function sameColumns(array $columns, bool $includeUpdatedAt = false): string
    {
        if ($includeUpdatedAt && ! in_array('updated_at', $columns, true)) {
            $columns[] = 'updated_at';
        }

        return collect($columns)
            ->map(fn (string $column): string => $this->sameColumn($column))
            ->implode(' and ');
    }

    private function sameColumn(string $column): string
    {
        return "((NEW.{$column} = OLD.{$column})"
            ." or (NEW.{$column} is null and OLD.{$column} is null))";
    }

    private function replaceContractGuard(
        string $table,
        string $name,
        string $insertValid,
        string $updateValid,
    ): void {
        $this->dropTrigger("{$name}_insert");
        $this->dropTrigger("{$name}_update");
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("create trigger `{$name}_insert` before insert on `{$table}` for each row begin if coalesce(({$insertValid}), 0) = 0 then signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end");
            DB::unprepared("create trigger `{$name}_update` before update on `{$table}` for each row begin if coalesce(({$updateValid}), 0) = 0 then signal sqlstate '45000' set message_text = 'email_live_contract_invalid'; end if; end");

            return;
        }
        DB::unprepared("create trigger `{$name}_insert` before insert on `{$table}` when coalesce(({$insertValid}), 0) = 0 begin select raise(abort, 'email_live_contract_invalid'); end");
        DB::unprepared("create trigger `{$name}_update` before update on `{$table}` when coalesce(({$updateValid}), 0) = 0 begin select raise(abort, 'email_live_contract_invalid'); end");
    }

    private function replaceNoDeleteGuard(string $table, string $name): void
    {
        $this->dropTrigger($name);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("create trigger `{$name}` before delete on `{$table}` for each row begin signal sqlstate '45000' set message_text = 'email_live_evidence_delete_forbidden'; end");

            return;
        }
        DB::unprepared("create trigger `{$name}` before delete on `{$table}` begin select raise(abort, 'email_live_evidence_delete_forbidden'); end");
    }

    private function replaceConditionalDeleteGuard(string $table, string $name, string $allowed): void
    {
        $this->dropTrigger($name);
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("create trigger `{$name}` before delete on `{$table}` for each row begin if coalesce(({$allowed}), 0) = 0 then signal sqlstate '45000' set message_text = 'email_live_evidence_delete_forbidden'; end if; end");

            return;
        }
        DB::unprepared("create trigger `{$name}` before delete on `{$table}` when coalesce(({$allowed}), 0) = 0 begin select raise(abort, 'email_live_evidence_delete_forbidden'); end");
    }

    private function replaceGenerationGuard(string $table, string $column, string $name): void
    {
        $this->dropTrigger("{$name}_insert");
        $this->dropTrigger("{$name}_update");
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::unprepared("create trigger `{$name}_insert` before insert on `{$table}` for each row begin if NEW.{$column} < 1 then signal sqlstate '45000' set message_text = 'email_live_generation_invalid'; end if; end");
            DB::unprepared("create trigger `{$name}_update` before update on `{$table}` for each row begin if NEW.{$column} < OLD.{$column} or NEW.{$column} < 1 then signal sqlstate '45000' set message_text = 'email_live_generation_not_monotonic'; end if; end");

            return;
        }
        DB::unprepared("create trigger `{$name}_insert` before insert on `{$table}` when NEW.{$column} < 1 begin select raise(abort, 'email_live_generation_invalid'); end");
        DB::unprepared("create trigger `{$name}_update` before update on `{$table}` when NEW.{$column} < OLD.{$column} or NEW.{$column} < 1 begin select raise(abort, 'email_live_generation_not_monotonic'); end");
    }

    private function dropGuards(): void
    {
        foreach ([
            'em_live_stream_contract',
            'em_live_change_contract',
            'em_live_publication_contract',
            'em_live_delivery_contract',
            'em_live_global_authority_contract',
            'em_live_account_authority_contract',
            'em_live_user_access_contract',
            'em_live_content_path_contract',
        ] as $name) {
            $this->dropTrigger("{$name}_insert");
            $this->dropTrigger("{$name}_update");
            $this->dropTrigger("{$name}_no_delete");
        }
        foreach (['em_live_user_generation_guard', 'em_live_account_owner_generation_guard', 'em_live_grant_generation_guard', 'em_live_delegate_generation_guard', 'em_live_break_generation_guard'] as $name) {
            $this->dropTrigger("{$name}_insert");
            $this->dropTrigger("{$name}_update");
        }
    }

    private function dropAuthorityCursorIndexes(): void
    {
        foreach ([
            ['email_accounts', 'em_live_account_owner_cursor_ix'],
            ['email_account_user_grants', 'em_live_grant_account_cursor_ix'],
            ['email_account_user_grants', 'em_live_grant_user_cursor_ix'],
            ['email_mailbox_delegations', 'em_live_delegate_account_cursor_ix'],
            ['email_mailbox_delegations', 'em_live_delegate_user_cursor_ix'],
            ['email_mailbox_delegations', 'em_live_delegate_start_boundary_ix'],
            ['email_mailbox_delegations', 'em_live_delegate_expiry_boundary_ix'],
            ['email_break_glass_accesses', 'em_live_break_account_cursor_ix'],
            ['email_break_glass_accesses', 'em_live_break_actor_cursor_ix'],
            ['email_break_glass_accesses', 'em_live_break_start_boundary_ix'],
            ['email_break_glass_accesses', 'em_live_break_expiry_boundary_ix'],
            ['user_management', 'em_live_user_status_cursor_ix'],
            ['email_folders', 'em_live_folder_account_cursor_ix'],
        ] as [$table, $name]) {
            if ($this->hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
            }
        }
    }

    private function dropAuthorityColumns(): void
    {
        foreach (['email_mailbox_delegations', 'email_break_glass_accesses'] as $table) {
            foreach (['email_live_start_invalidated_at', 'email_live_expiry_invalidated_at'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
                }
            }
        }
        foreach ([
            ['email_accounts', 'email_live_owner_enable_generation'],
            ['user_management', 'email_live_enable_generation'],
            ['email_account_user_grants', 'email_live_enable_generation'],
            ['email_mailbox_delegations', 'email_live_enable_generation'],
            ['email_break_glass_accesses', 'email_live_enable_generation'],
        ] as [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($column));
            }
        }
    }

    /** @param list<string> $columns */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if ($this->hasIndex($table, $name)) {
            return;
        }
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function hasIndex(string $table, string $name): bool
    {
        return Schema::hasTable($table) && collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
    }

    private function dropTrigger(string $name): void
    {
        DB::unprepared("drop trigger if exists `{$name}`");
    }

    private function exists(string $table): bool
    {
        return Schema::hasTable($table) && DB::table($table)->limit(1)->exists();
    }

    private function repositoryIsSealed(): bool
    {
        $repository = (string) config('database.migrations.table', 'migrations');

        return $repository !== '' && Schema::hasTable($repository)
            && DB::table($repository)->where('migration', self::MIGRATION)->exists();
    }
};
