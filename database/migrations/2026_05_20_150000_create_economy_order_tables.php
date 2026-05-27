<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('economy_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('create_orders_from_resolved_ticket_time')->default(false);
            $table->boolean('create_orders_from_closed_ticket_time')->default(true);
            $table->boolean('include_unresolved_ticket_time_in_period_close')->default(false);
            $table->boolean('create_orders_from_picked_ticket_costs')->default(true);
            $table->boolean('auto_pick_ticket_costs_on_resolved_or_closed_ticket')->default(false);
            $table->string('time_order_line_grouping')->default('per_entry');
            $table->string('order_line_text_format')->default('ticket_date_text');
            $table->string('order_prefix')->default('ORD-');
            $table->decimal('default_vat_rate', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('economy_orders', function (Blueprint $table): void {
            $table->id();
            $table->string('order_number')->nullable()->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status')->default('draft')->index();
            $table->decimal('subtotal_ex_vat', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('total_inc_vat', 12, 2)->default(0);
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'period_start', 'period_end', 'status'], 'economy_orders_client_period_status_idx');
        });

        Schema::create('economy_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('economy_order_id')->constrained('economy_orders')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->nullableMorphs('source');
            $table->unsignedBigInteger('ticket_id')->nullable()->index();
            $table->date('work_date')->nullable()->index();
            $table->string('line_type')->index();
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->string('unit', 20);
            $table->decimal('unit_price_ex_vat', 12, 4)->nullable();
            $table->decimal('line_total_ex_vat', 12, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->decimal('vat_amount', 12, 2)->nullable();
            $table->decimal('total_inc_vat', 12, 2)->default(0);
            $table->string('currency', 3)->default('NOK');
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id'], 'economy_order_lines_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('economy_order_lines');
        Schema::dropIfExists('economy_orders');
        Schema::dropIfExists('economy_settings');
    }
};
