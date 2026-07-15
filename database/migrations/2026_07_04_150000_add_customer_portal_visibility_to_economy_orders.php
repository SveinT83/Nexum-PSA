<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('economy_orders', function (Blueprint $table): void {
            $table->timestamp('portal_visible_at')->nullable()->after('cancelled_at')->index();
            $table->foreignId('portal_visible_by')->nullable()->after('portal_visible_at')->constrained('user_management')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('economy_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('portal_visible_by');
            $table->dropColumn('portal_visible_at');
        });
    }
};
