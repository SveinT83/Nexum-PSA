<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_time_entry_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_time_entry_id')->unique()->constrained('ticket_time_entries')->cascadeOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('included_minutes')->default(0);
            $table->unsignedInteger('covered_minutes')->default(0);
            $table->unsignedInteger('billable_minutes')->default(0);
            $table->foreignId('economy_order_line_id')->nullable()->constrained('economy_order_lines')->nullOnDelete();
            $table->string('status')->default('calculated')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'period_start', 'period_end'], 'ticket_time_alloc_client_period_idx');
            $table->index(['contract_item_id', 'period_start', 'period_end'], 'ticket_time_alloc_contract_item_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_time_entry_allocations');
    }
};
