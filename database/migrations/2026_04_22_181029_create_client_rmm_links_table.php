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
        Schema::create('client_rmm_links', function (Blueprint $table) {
            $table->id();
            $table->uuid('integration_id');
            $table->string('external_id');
            $table->string('linkable_type');
            $table->unsignedBigInteger('linkable_id');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['integration_id', 'external_id', 'linkable_type'], 'integration_external_type_unique');
            $table->index(['linkable_type', 'linkable_id']);
            $table->foreign('integration_id')->references('id')->on('integrations')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_rmm_links');
    }
};
