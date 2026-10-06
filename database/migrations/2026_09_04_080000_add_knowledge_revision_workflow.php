<?php

use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'knowledge.manage_drafts',
        'knowledge.approve',
        'knowledge.publish',
        'knowledge.rollback',
        'knowledge.admin',
        'knowledge.revision_persist',
        'knowledge.publish_system',
    ];

    private const ROLE_PERMISSIONS = [
        'Tech' => ['knowledge.manage_drafts'],
        'Admin' => [
            'knowledge.manage_drafts',
            'knowledge.approve',
            'knowledge.publish',
            'knowledge.rollback',
            'knowledge.admin',
        ],
        'Superuser' => [
            'knowledge.manage_drafts',
            'knowledge.approve',
            'knowledge.publish',
            'knowledge.rollback',
            'knowledge.admin',
        ],
    ];

    public function up(): void
    {
        Schema::table('knowledge_article_revisions', function (Blueprint $table): void {
            $table->char('snapshot_hash', 64)->nullable()->after('content_hash');
            $table->foreignId('owner_id')->nullable()->after('client_scope_id')->constrained('user_management')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('owner_id')->constrained('categories')->nullOnDelete();
            $table->timestamp('next_review_at')->nullable()->after('priority');
            $table->json('audience_snapshot')->nullable()->after('next_review_at');
            $table->string('source_system')->nullable()->after('origin');
            $table->string('source_version')->nullable()->after('source_id');
            $table->foreignId('human_author_id')->nullable()->after('created_by')->constrained('user_management')->nullOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->after('human_author_id')->constrained('ai_agents')->nullOnDelete();
            $table->foreignId('system_actor_id')->nullable()->after('ai_agent_id')->constrained('user_management')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->after('system_actor_id')->constrained('tickets')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->after('ticket_id')->constrained('user_management')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('submitted_by')->constrained('user_management')->nullOnDelete();
            $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('user_management')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->after('rejected_by')->constrained('user_management')->nullOnDelete();
            $table->text('decision_note')->nullable()->after('published_by');
            $table->timestamp('submitted_at')->nullable()->after('decision_note');
            $table->timestamp('rejected_at')->nullable()->after('approved_at');
            $table->timestamp('published_at')->nullable()->after('rejected_at');
            $table->timestamp('superseded_at')->nullable()->after('published_at');
            $table->foreignId('supersedes_revision_id')->nullable()->after('superseded_at')->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->string('publication_status')->nullable()->after('supersedes_revision_id');
            $table->json('publication_read_back')->nullable()->after('publication_status');
            $table->timestamp('publication_read_back_at')->nullable()->after('publication_read_back');
            $table->text('publication_error')->nullable()->after('publication_read_back_at');
            $table->unsignedInteger('publication_attempts')->default(0)->after('publication_error');

            $table->index(['article_id', 'state', 'revision_number'], 'knowledge_revision_workflow_state_index');
            $table->index(['ticket_id', 'state'], 'knowledge_revision_ticket_state_index');
            $table->index(['publication_status', 'updated_at'], 'knowledge_revision_publication_index');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->foreignId('published_revision_id')
                ->nullable()
                ->after('updated_by')
                ->constrained('knowledge_article_revisions')
                ->nullOnDelete();
        });

        Schema::create('knowledge_documentation_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('request_event_id')->nullable()->constrained('ticket_events')->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->foreignId('revision_id')->nullable()->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('status')->default('open');
            $table->text('reason')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('publication_read_back_at')->nullable();
            $table->string('failure_code')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'status'], 'knowledge_documentation_ticket_status_index');
            $table->index(['status', 'updated_at'], 'knowledge_documentation_status_index');
        });

        Schema::table('knowledge_article_revisions', function (Blueprint $table): void {
            $table->foreignId('documentation_request_id')
                ->nullable()
                ->after('ticket_id')
                ->constrained('knowledge_documentation_requests')
                ->nullOnDelete();
        });

        Schema::create('knowledge_article_revision_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_revision_id')->constrained('knowledge_article_revisions')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('event_type');
            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['article_revision_id', 'id'], 'knowledge_revision_event_timeline_index');
            $table->index(['event_type', 'created_at'], 'knowledge_revision_event_type_index');
        });

        $this->deployPermissions();
        $this->baselineArticles();
        $this->backfillRevisionSnapshots();

        app(EnsureSystemActor::class)->handle(
            key: 'knowledge_documentation_agent',
            name: 'Nexum Documentation Agent',
            email: 'documentation-agent@system.nexum.invalid',
            permissions: ['knowledge.revision_persist', 'knowledge.publish_system'],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_article_revision_events');

        Schema::table('knowledge_article_revisions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('documentation_request_id');
        });

        Schema::dropIfExists('knowledge_documentation_requests');

        Schema::table('articles', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_revision_id');
        });

        Schema::table('knowledge_article_revisions', function (Blueprint $table): void {
            $table->dropIndex('knowledge_revision_workflow_state_index');
            $table->dropIndex('knowledge_revision_ticket_state_index');
            $table->dropIndex('knowledge_revision_publication_index');
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('human_author_id');
            $table->dropConstrainedForeignId('ai_agent_id');
            $table->dropConstrainedForeignId('system_actor_id');
            $table->dropConstrainedForeignId('ticket_id');
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('published_by');
            $table->dropConstrainedForeignId('supersedes_revision_id');
            $table->dropColumn([
                'snapshot_hash',
                'next_review_at',
                'audience_snapshot',
                'source_system',
                'source_version',
                'decision_note',
                'submitted_at',
                'rejected_at',
                'published_at',
                'superseded_at',
                'publication_status',
                'publication_read_back',
                'publication_read_back_at',
                'publication_error',
                'publication_attempts',
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function deployPermissions(): void
    {
        $tableNames = config('permission.table_names');
        $columnNames = config('permission.column_names');
        $permissionsTable = $tableNames['permissions'] ?? null;
        $rolesTable = $tableNames['roles'] ?? null;
        $rolePermissionsTable = $tableNames['role_has_permissions'] ?? null;
        $permissionPivot = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $rolePivot = $columnNames['role_pivot_key'] ?? 'role_id';

        if (! is_string($permissionsTable)
            || ! is_string($rolesTable)
            || ! is_string($rolePermissionsTable)
            || ! Schema::hasTable($permissionsTable)
            || ! Schema::hasTable($rolesTable)
            || ! Schema::hasTable($rolePermissionsTable)) {
            throw new RuntimeException('The permission schema must exist before Knowledge workflow permissions are deployed.');
        }

        DB::transaction(function () use (
            $permissionsTable,
            $rolesTable,
            $rolePermissionsTable,
            $permissionPivot,
            $rolePivot,
        ): void {
            $now = now();

            foreach (self::PERMISSIONS as $permission) {
                DB::table($permissionsTable)->insertOrIgnore([
                    'name' => $permission,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $permissionIds = DB::table($permissionsTable)
                ->where('guard_name', 'web')
                ->whereIn('name', self::PERMISSIONS)
                ->pluck('id', 'name');

            if ($permissionIds->count() !== count(self::PERMISSIONS)) {
                throw new RuntimeException('The complete Knowledge workflow permission catalog could not be deployed.');
            }

            $roleIds = DB::table($rolesTable)
                ->where('guard_name', 'web')
                ->whereIn('name', array_keys(self::ROLE_PERMISSIONS))
                ->pluck('id', 'name');

            foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
                $roleId = $roleIds->get($roleName);

                if ($roleId === null) {
                    continue;
                }

                foreach ($permissions as $permission) {
                    DB::table($rolePermissionsTable)->insertOrIgnore([
                        $permissionPivot => $permissionIds->get($permission),
                        $rolePivot => $roleId,
                    ]);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function baselineArticles(): void
    {
        DB::table('articles')
            ->orderBy('id')
            ->chunkById(100, function ($articles): void {
                foreach ($articles as $article) {
                    $revision = DB::table('knowledge_article_revisions')
                        ->where('article_id', $article->id)
                        ->where('title', $article->title)
                        ->where('body_markdown', $article->body_markdown)
                        ->where('visibility', $article->visibility)
                        ->where('article_status', $article->status)
                        ->where('state', 'not like', '%_candidate')
                        ->orderByDesc('revision_number')
                        ->first();
                    $now = now();
                    $snapshot = $this->snapshot($article);

                    if ($revision) {
                        DB::table('knowledge_article_revisions')
                            ->where('id', $revision->id)
                            ->update([
                                'snapshot_hash' => $this->snapshotHash($snapshot),
                                'state' => $article->status === 'published' ? 'published' : 'draft',
                                'owner_id' => $article->owner_id,
                                'category_id' => $article->category_id,
                                'next_review_at' => $article->next_review_at,
                                'audience_snapshot' => json_encode($snapshot['audience'], JSON_THROW_ON_ERROR),
                                'source_system' => $article->source_system,
                                'source_version' => $article->source_checksum,
                                'human_author_id' => $article->updated_by ?: $article->created_by,
                                'approved_by' => $article->status === 'published' ? ($article->updated_by ?: $article->created_by) : null,
                                'published_at' => $article->status === 'published' ? ($article->updated_at ?: $now) : null,
                                'publication_status' => $article->status === 'published' ? 'verified' : null,
                                'publication_read_back' => $article->status === 'published'
                                    ? json_encode(['local' => 'baseline_verified'], JSON_THROW_ON_ERROR)
                                    : null,
                                'publication_read_back_at' => $article->status === 'published' ? $now : null,
                                'updated_at' => $now,
                            ]);
                        $revisionId = $revision->id;
                    } else {
                        $lastNumber = (int) DB::table('knowledge_article_revisions')
                            ->where('article_id', $article->id)
                            ->max('revision_number');
                        $revisionId = DB::table('knowledge_article_revisions')->insertGetId([
                            'article_id' => $article->id,
                            'revision_number' => $lastNumber + 1,
                            'base_revision_id' => null,
                            'content_hash' => $this->contentHash($article),
                            'snapshot_hash' => $this->snapshotHash($snapshot),
                            'state' => $article->status === 'published' ? 'published' : 'draft',
                            'origin' => 'migration_baseline',
                            'source_system' => $article->source_system,
                            'title' => $article->title,
                            'body_markdown' => $article->body_markdown,
                            'body_html' => $article->body_html,
                            'visibility' => $article->visibility,
                            'article_status' => $article->status,
                            'client_scope_id' => $article->client_scope_id,
                            'owner_id' => $article->owner_id,
                            'category_id' => $article->category_id,
                            'knowledge_shelf_id' => $article->knowledge_shelf_id,
                            'knowledge_book_id' => $article->knowledge_book_id,
                            'knowledge_chapter_id' => $article->knowledge_chapter_id,
                            'priority' => (int) $article->priority,
                            'next_review_at' => $article->next_review_at,
                            'audience_snapshot' => json_encode($snapshot['audience'], JSON_THROW_ON_ERROR),
                            'source_type' => $article->source_type,
                            'source_id' => $article->source_id,
                            'source_version' => $article->source_checksum,
                            'created_by' => $article->updated_by ?: $article->created_by,
                            'human_author_id' => $article->updated_by ?: $article->created_by,
                            'approved_by' => $article->status === 'published' ? ($article->updated_by ?: $article->created_by) : null,
                            'approved_at' => $article->status === 'published' ? ($article->updated_at ?: $now) : null,
                            'published_at' => $article->status === 'published' ? ($article->updated_at ?: $now) : null,
                            'publication_status' => $article->status === 'published' ? 'verified' : null,
                            'publication_read_back' => $article->status === 'published'
                                ? json_encode(['local' => 'baseline_verified'], JSON_THROW_ON_ERROR)
                                : null,
                            'publication_read_back_at' => $article->status === 'published' ? $now : null,
                            'created_at' => $article->created_at ?: $now,
                            'updated_at' => $now,
                        ]);
                    }

                    if ($article->status === 'published') {
                        DB::table('articles')
                            ->where('id', $article->id)
                            ->update(['published_revision_id' => $revisionId]);

                        DB::table('knowledge_article_revision_events')->insert([
                            'article_revision_id' => $revisionId,
                            'actor_id' => $article->updated_by ?: $article->created_by,
                            'event_type' => 'baseline_verified',
                            'from_state' => null,
                            'to_state' => 'published',
                            'note' => 'Existing published Knowledge content was baselined during workflow deployment.',
                            'metadata' => json_encode(['read_back' => 'local'], JSON_THROW_ON_ERROR),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    private function backfillRevisionSnapshots(): void
    {
        DB::table('knowledge_article_revisions')
            ->whereNull('snapshot_hash')
            ->orderBy('id')
            ->chunkById(100, function ($revisions): void {
                foreach ($revisions as $revision) {
                    $article = DB::table('articles')->where('id', $revision->article_id)->first();

                    if (! $article) {
                        continue;
                    }

                    $snapshot = [
                        'title' => (string) $revision->title,
                        'body_markdown' => $this->normalizeMarkdown((string) $revision->body_markdown),
                        'body_html' => (string) ($revision->body_html ?? ''),
                        'visibility' => (string) $revision->visibility,
                        'article_status' => (string) $revision->article_status,
                        'client_scope_id' => $revision->client_scope_id,
                        'owner_id' => $revision->owner_id ?? $article->owner_id,
                        'category_id' => $revision->category_id ?? $article->category_id,
                        'knowledge_shelf_id' => $revision->knowledge_shelf_id,
                        'knowledge_book_id' => $revision->knowledge_book_id,
                        'knowledge_chapter_id' => $revision->knowledge_chapter_id,
                        'priority' => (int) $revision->priority,
                        'next_review_at' => $revision->next_review_at ?? $article->next_review_at,
                        'audience' => [
                            'version' => 1,
                            'visibility' => (string) $revision->visibility,
                            'client_scope_id' => $revision->client_scope_id,
                        ],
                    ];

                    DB::table('knowledge_article_revisions')
                        ->where('id', $revision->id)
                        ->update([
                            'snapshot_hash' => $this->snapshotHash($snapshot),
                            'owner_id' => $snapshot['owner_id'],
                            'category_id' => $snapshot['category_id'],
                            'next_review_at' => $snapshot['next_review_at'],
                            'audience_snapshot' => json_encode($snapshot['audience'], JSON_THROW_ON_ERROR),
                            'source_system' => $revision->source_system ?? $article->source_system,
                            'source_version' => $revision->source_version ?? $article->source_checksum,
                            'human_author_id' => $revision->human_author_id ?? $revision->created_by,
                        ]);
                }
            });
    }

    /** @return array<string, mixed> */
    private function snapshot(object $article): array
    {
        return [
            'title' => (string) $article->title,
            'body_markdown' => $this->normalizeMarkdown((string) $article->body_markdown),
            'body_html' => (string) ($article->body_html ?? ''),
            'visibility' => (string) $article->visibility,
            'article_status' => (string) $article->status,
            'client_scope_id' => $article->client_scope_id,
            'owner_id' => $article->owner_id,
            'category_id' => $article->category_id,
            'knowledge_shelf_id' => $article->knowledge_shelf_id,
            'knowledge_book_id' => $article->knowledge_book_id,
            'knowledge_chapter_id' => $article->knowledge_chapter_id,
            'priority' => (int) $article->priority,
            'next_review_at' => $article->next_review_at,
            'audience' => [
                'version' => 1,
                'visibility' => (string) $article->visibility,
                'client_scope_id' => $article->client_scope_id,
            ],
        ];
    }

    private function contentHash(object $article): string
    {
        $identity = [
            'name' => trim((string) $article->title),
            'markdown' => $this->normalizeMarkdown((string) $article->body_markdown),
            'draft' => $article->status !== 'published',
            'priority' => (int) $article->priority,
            'book_id' => $this->externalStructureId('knowledge_books', $article->knowledge_book_id),
            'chapter_id' => $this->externalStructureId('knowledge_chapters', $article->knowledge_chapter_id),
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $snapshot */
    private function snapshotHash(array $snapshot): string
    {
        unset($snapshot['audience']);

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function externalStructureId(string $table, mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $structure = DB::table($table)->where('id', $id)->first(['source_system', 'source_id']);

        return $structure?->source_system === 'book_stack' && filled($structure?->source_id)
            ? (string) $structure->source_id
            : 'nexum:'.$id;
    }

    private function normalizeMarkdown(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", trim($markdown));

        return preg_replace('/<!--\s*nexum-sync:[a-f0-9]{64}\s*-->\s*$/i', '', $markdown) ?? $markdown;
    }
};
