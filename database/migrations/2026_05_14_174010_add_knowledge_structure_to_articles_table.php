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
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('knowledge_shelf_id')->nullable()->after('client_scope_id')->constrained('knowledge_shelves')->nullOnDelete();
            $table->foreignId('knowledge_book_id')->nullable()->after('knowledge_shelf_id')->constrained('knowledge_books')->nullOnDelete();
            $table->foreignId('knowledge_chapter_id')->nullable()->after('knowledge_book_id')->constrained('knowledge_chapters')->nullOnDelete();
            $table->unsignedInteger('priority')->default(0)->after('knowledge_chapter_id');

            $table->index(['knowledge_book_id', 'knowledge_chapter_id', 'priority'], 'articles_knowledge_structure_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex('articles_knowledge_structure_index');
            $table->dropConstrainedForeignId('knowledge_chapter_id');
            $table->dropConstrainedForeignId('knowledge_book_id');
            $table->dropConstrainedForeignId('knowledge_shelf_id');
            $table->dropColumn('priority');
        });
    }
};
