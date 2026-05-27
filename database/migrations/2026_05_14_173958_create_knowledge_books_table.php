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
        Schema::create('knowledge_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id')->nullable()->constrained('knowledge_shelves')->nullOnDelete();
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

            $table->unique(['source_system', 'source_type', 'source_id'], 'knowledge_books_source_unique');
            $table->index(['shelf_id', 'priority']);
            $table->index(['source_system', 'sync_status'], 'knowledge_books_source_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_books');
    }
};
