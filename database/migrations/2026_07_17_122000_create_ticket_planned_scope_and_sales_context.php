<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_planned_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('line_type')->default('custom');
            $table->string('source_type')->default('custom');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('storage_item_id')->nullable()->constrained('storage_items')->nullOnDelete();
            $table->string('section')->default('one_time_costs');
            $table->string('downstream_type')->default('one_time_order');
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('unit_cost_ex_vat', 12, 2)->default(0);
            $table->decimal('unit_price_ex_vat', 12, 2)->default(0);
            $table->decimal('vat_rate', 8, 2)->default(25);
            $table->string('status')->default('planned')->index();
            $table->foreignId('approved_quote_version_id')->nullable()->constrained('sales_quote_versions')->nullOnDelete();
            $table->foreignId('converted_cost_entry_id')->nullable()->constrained('ticket_cost_entries')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->json('snapshot')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'status']);
        });

        Schema::create('ticket_sales_contexts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('opportunity_id')->unique()->constrained('sales_opportunities')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('sales_quotes')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            $table->string('pdf_snapshot_disk')->nullable()->after('snapshots');
            $table->string('pdf_snapshot_path')->nullable()->after('pdf_snapshot_disk');
            $table->string('pdf_snapshot_sha256', 64)->nullable()->after('pdf_snapshot_path');
            $table->string('accepted_method')->nullable()->after('accepted_ua');
            $table->foreignId('accepted_by_user_id')->nullable()->after('accepted_method')->constrained('user_management')->nullOnDelete();
            $table->foreignId('accepted_ticket_message_id')->nullable()->after('accepted_by_user_id')->constrained('ticket_messages')->nullOnDelete();
            $table->json('acceptance_metadata')->nullable()->after('accepted_ticket_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('accepted_by_user_id');
            $table->dropConstrainedForeignId('accepted_ticket_message_id');
            $table->dropColumn([
                'pdf_snapshot_disk',
                'pdf_snapshot_path',
                'pdf_snapshot_sha256',
                'accepted_method',
                'acceptance_metadata',
            ]);
        });

        Schema::dropIfExists('ticket_sales_contexts');
        Schema::dropIfExists('ticket_planned_lines');
    }
};
