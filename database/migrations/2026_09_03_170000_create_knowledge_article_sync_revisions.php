<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_article_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->foreignId('base_revision_id')->nullable()->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->char('content_hash', 64);
            $table->string('state');
            $table->string('origin');
            $table->string('title');
            $table->mediumText('body_markdown');
            $table->mediumText('body_html')->nullable();
            $table->string('visibility');
            $table->string('article_status');
            $table->foreignId('client_scope_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('knowledge_shelf_id')->nullable()->constrained('knowledge_shelves')->nullOnDelete();
            $table->foreignId('knowledge_book_id')->nullable()->constrained('knowledge_books')->nullOnDelete();
            $table->foreignId('knowledge_chapter_id')->nullable()->constrained('knowledge_chapters')->nullOnDelete();
            $table->unsignedInteger('priority')->default(0);
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'revision_number'], 'knowledge_article_revision_number_unique');
            $table->index(['article_id', 'content_hash'], 'knowledge_article_revision_hash_index');
            $table->index(['state', 'origin'], 'knowledge_article_revision_state_origin_index');
        });

        Schema::create('knowledge_book_stack_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('article_id')->unique()->constrained('articles')->cascadeOnDelete();
            $table->foreignId('last_synced_revision_id')->nullable()->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->foreignId('pending_revision_id')->nullable()->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->foreignId('candidate_revision_id')->nullable()->constrained('knowledge_article_revisions')->nullOnDelete();
            $table->string('external_type')->default('page');
            $table->string('external_id')->nullable()->unique();
            $table->text('external_url')->nullable();
            $table->string('status')->default('baseline_unknown');
            $table->char('last_synced_local_hash', 64)->nullable();
            $table->char('last_synced_remote_hash', 64)->nullable();
            $table->char('outbound_operation_key', 64)->nullable();
            $table->string('last_direction')->nullable();
            $table->string('origin')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('remote_updated_at')->nullable();
            $table->string('conflict_reason')->nullable();
            $table->json('remote_snapshot')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at'], 'knowledge_book_stack_sync_status_index');
        });

        $now = now();

        DB::table('articles')
            ->where('source_system', 'book_stack')
            ->where('source_type', 'page')
            ->orderBy('id')
            ->chunkById(250, function ($articles) use ($now): void {
                $rows = $articles->map(fn ($article): array => [
                    'article_id' => $article->id,
                    'external_type' => 'page',
                    'external_id' => $article->source_id,
                    'external_url' => $article->source_url,
                    'status' => filled($article->source_id) ? 'baseline_unknown' : 'remote_missing_identifier',
                    'origin' => 'migration',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                DB::table('knowledge_book_stack_sync_states')->insert($rows);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_book_stack_sync_states');
        Schema::dropIfExists('knowledge_article_revisions');
    }
};
