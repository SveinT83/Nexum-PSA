<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Match existing actor ID columns: user tables are installation-configurable.
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('actor_updated_at')->nullable();
        });
        Schema::table('tickets', function (Blueprint $table): void {
            $table->timestamp('actor_updated_at')->nullable();
        });
        // Deliberately no historical backfill: old updater evidence may be ambiguous.
    }

    public function down(): void
    {
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn('actor_updated_at'));
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex(['updated_by']);
            $table->dropColumn(['updated_by', 'actor_updated_at']);
        });
    }
};
