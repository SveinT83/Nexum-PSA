<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_opportunities', 'is_unread')) {
                $table->boolean('is_unread')->default(false)->after('next_follow_up_note');
            }
        });

        Schema::table('sales_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_activities', 'is_unread')) {
                $table->boolean('is_unread')->default(false)->after('body');
            }

            if (! Schema::hasColumn('sales_activities', 'read_at')) {
                $table->timestamp('read_at')->nullable()->after('is_unread');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_activities', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_activities', 'read_at')) {
                $table->dropColumn('read_at');
            }

            if (Schema::hasColumn('sales_activities', 'is_unread')) {
                $table->dropColumn('is_unread');
            }
        });

        Schema::table('sales_opportunities', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_opportunities', 'is_unread')) {
                $table->dropColumn('is_unread');
            }
        });
    }
};
