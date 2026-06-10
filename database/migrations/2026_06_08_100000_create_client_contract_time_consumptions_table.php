<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_contract_time_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('contracts')->cascadeOnDelete();
            $table->foreignId('contract_item_id')->constrained('contract_items')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->date('work_date');
            $table->unsignedInteger('minutes');
            $table->text('note')->nullable();
            $table->string('source')->default('quick_client');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('included_minutes_snapshot')->default(0);
            $table->unsignedInteger('used_before_minutes_snapshot')->default(0);
            $table->unsignedInteger('overused_minutes')->default(0);
            $table->timestamps();

            $table->index(['client_id', 'period_start', 'period_end'], 'client_time_consumptions_client_period_idx');
            $table->index(['contract_item_id', 'period_start', 'period_end'], 'client_time_consumptions_item_period_idx');
            $table->index(['user_id', 'work_date'], 'client_time_consumptions_user_work_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_contract_time_consumptions');
    }
};
