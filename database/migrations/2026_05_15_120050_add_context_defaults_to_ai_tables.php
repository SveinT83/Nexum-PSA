<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add page-context metadata and per-domain default agent mapping.
     */
    public function up(): void
    {
        if (Schema::hasTable('ai_agents') && ! Schema::hasColumn('ai_agents', 'default_domains')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                $table->json('default_domains')->nullable()->after('is_default');
            });
        }

        if (Schema::hasTable('ai_chats') && ! Schema::hasColumn('ai_chats', 'metadata')) {
            Schema::table('ai_chats', function (Blueprint $table) {
                $table->json('metadata')->nullable()->after('status');
            });
        }
    }

    /**
     * Remove context metadata columns.
     */
    public function down(): void
    {
        if (Schema::hasTable('ai_chats') && Schema::hasColumn('ai_chats', 'metadata')) {
            Schema::table('ai_chats', function (Blueprint $table) {
                $table->dropColumn('metadata');
            });
        }

        if (Schema::hasTable('ai_agents') && Schema::hasColumn('ai_agents', 'default_domains')) {
            Schema::table('ai_agents', function (Blueprint $table) {
                $table->dropColumn('default_domains');
            });
        }
    }
};
