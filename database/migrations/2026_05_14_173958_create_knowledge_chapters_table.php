<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('knowledge_books')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->string('source_system')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_checksum')->nullable();
            $table->timestamp('source_synced_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->unique(['source_system', 'source_type', 'source_id'], 'knowledge_chapters_source_unique');
            $table->index(['book_id', 'priority']);
            $table->index(['source_system', 'sync_status'], 'knowledge_chapters_source_status_index');
        });

        Schema::table('articles', function (Blueprint $table): void {
            $table->foreign('knowledge_shelf_id')
                ->references('id')
                ->on('knowledge_shelves')
                ->nullOnDelete();

            $table->foreign('knowledge_book_id')
                ->references('id')
                ->on('knowledge_books')
                ->nullOnDelete();

            $table->foreign('knowledge_chapter_id')
                ->references('id')
                ->on('knowledge_chapters')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            $table->dropForeign(['knowledge_shelf_id']);
            $table->dropForeign(['knowledge_book_id']);
            $table->dropForeign(['knowledge_chapter_id']);
        });

        Schema::dropIfExists('knowledge_chapters');
    }
};
