<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_purchase_order_automation_policies', function (Blueprint $table): void {
            $table->string('ai_profile_learning_mode')->default('off')->after('ai_mode');
            $table->unsignedTinyInteger('ai_profile_shadow_samples')->default(3)->after('ai_profile_learning_mode');
        });
    }

    public function down(): void
    {
        Schema::table('storage_purchase_order_automation_policies', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_profile_learning_mode',
                'ai_profile_shadow_samples',
            ]);
        });
    }
};
