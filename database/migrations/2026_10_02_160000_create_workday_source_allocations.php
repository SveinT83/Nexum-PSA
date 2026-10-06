<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Queryable reservations for active draft/confirmed revisions; original source rows are never owned here.
        Schema::create('workday_source_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('workday_revisions')->restrictOnDelete();
            $table->string('source_key', 100);
            $table->unsignedInteger('minutes');
            $table->index(['source_key', 'revision_id'], 'workday_source_revision_index');
        });
    }

    public function down(): void
    {
        if (DB::table('workday_source_allocations')->exists()) {
            throw new RuntimeException('Retained source allocations require reviewed retention; disable Workday instead.');
        }
        Schema::dropIfExists('workday_source_allocations');
    }
};
