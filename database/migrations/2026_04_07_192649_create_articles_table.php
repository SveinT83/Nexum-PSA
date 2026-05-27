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
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('body_markdown');
            $table->text('body_html')->nullable();
            $table->string('visibility')->default('internal'); // internal, client-wide, public
            $table->string('status')->default('draft'); // draft, published, archived, needs_review
            $table->foreignId('owner_id')->constrained('user_management');
            $table->foreignId('category_id')->nullable()->constrained('categories')->onDelete('set null');
            $table->foreignId('knowledge_shelf_id')->nullable()->constrained('knowledge_shelves')->nullOnDelete();
            $table->foreignId('knowledge_book_id')->nullable()->constrained('knowledge_books')->nullOnDelete();
            $table->foreignId('knowledge_chapter_id')->nullable()->constrained('knowledge_chapters')->nullOnDelete();
            $table->foreignId('client_scope_id')->nullable()->constrained('clients');
            $table->unsignedInteger('priority')->default(0);
            $table->integer('view_count')->default(0);
            $table->timestamp('next_review_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management');
            $table->foreignId('updated_by')->nullable()->constrained('user_management');
            $table->string('source_system')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->text('source_url')->nullable();
            $table->string('source_checksum')->nullable();
            $table->timestamp('source_synced_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->string('sync_status')->nullable();
            $table->json('source_payload')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['source_system', 'source_type', 'source_id'], 'articles_source_unique');
            $table->index(['source_system', 'sync_status']);
            $table->index(['knowledge_shelf_id', 'knowledge_book_id', 'knowledge_chapter_id', 'priority'], 'articles_knowledge_structure_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
