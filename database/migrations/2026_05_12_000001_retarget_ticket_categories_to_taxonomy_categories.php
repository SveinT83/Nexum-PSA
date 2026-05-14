<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
    |--------------------------------------------------------------------------
    | Ticket categories now use Taxonomy categories
    |--------------------------------------------------------------------------
    |
    | Early Ticket work introduced a ticket_categories table, but the project
    | already owns shared categories in the Taxonomy module. This migration keeps
    | ticket.category_id and retargets its foreign key to categories.id.
    |
    */
    public function up(): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('categories')) {
            return;
        }

        DB::table('tickets')
            ->whereNotNull('category_id')
            ->whereNotIn('category_id', DB::table('categories')->select('id'))
            ->update(['category_id' => null]);

        $this->dropForeignIfExists('tickets', ['category_id']);

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tickets') || ! Schema::hasTable('ticket_categories')) {
            return;
        }

        DB::table('tickets')
            ->whereNotNull('category_id')
            ->whereNotIn('category_id', DB::table('ticket_categories')->select('id'))
            ->update(['category_id' => null]);

        $this->dropForeignIfExists('tickets', ['category_id']);

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreign('category_id')
                ->references('id')
                ->on('ticket_categories')
                ->nullOnDelete();
        });
    }

    private function dropForeignIfExists(string $table, array $columns): void
    {
        try {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                $table->dropForeign($columns);
            });
        } catch (Throwable) {
            // Some local databases may already have the desired constraint, or no
            // constraint at all. The following add-foreign step is the important
            // target state for environments that need the retargeting.
        }
    }
};
