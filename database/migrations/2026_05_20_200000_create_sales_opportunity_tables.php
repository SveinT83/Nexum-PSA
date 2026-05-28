<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('opportunity_key')->unique();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('primary_contact_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('title');
            $table->string('type')->default('service_agreement');
            $table->string('status')->default('new_lead')->index();
            $table->text('summary')->nullable();
            $table->text('needs')->nullable();
            $table->unsignedInteger('employee_count_estimate')->nullable();
            $table->unsignedInteger('user_count_estimate')->nullable();
            $table->unsignedInteger('workstation_count_estimate')->nullable();
            $table->unsignedInteger('server_count_estimate')->nullable();
            $table->unsignedInteger('site_count_estimate')->nullable();
            $table->decimal('estimated_value_ex_vat', 12, 2)->default(0);
            $table->unsignedTinyInteger('probability_percent')->default(10);
            $table->decimal('weighted_value_ex_vat', 12, 2)->default(0);
            $table->date('expected_close_date')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->string('next_follow_up_type')->nullable();
            $table->text('next_follow_up_note')->nullable();
            $table->boolean('is_unread')->default(false);
            $table->foreignId('follow_up_calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();
            $table->foreignId('current_quote_version_id')->nullable();
            $table->foreignId('won_quote_version_id')->nullable();
            $table->timestamp('won_at')->nullable();
            $table->timestamp('lost_at')->nullable();
            $table->text('lost_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index('next_follow_up_at');
        });

        Schema::create('sales_opportunity_stakeholders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->string('role')->default('stakeholder');
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['opportunity_id', 'role']);
        });

        Schema::create('sales_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('user_management')->nullOnDelete();
            $table->string('type')->default('journal');
            $table->string('direction')->nullable();
            $table->string('subject')->nullable();
            $table->longText('body')->nullable();
            $table->boolean('is_unread')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['opportunity_id', 'type']);
            $table->index(['opportunity_id', 'is_unread']);
        });

        Schema::create('sales_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('opportunity_id')->constrained('sales_opportunities')->cascadeOnDelete();
            $table->string('quote_key')->unique();
            $table->string('status')->default('draft')->index();
            $table->foreignId('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sales_quote_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('sales_quotes')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->default(1);
            $table->string('status')->default('draft')->index();
            $table->string('secure_token')->unique();
            $table->string('title');
            $table->longText('intro_text')->nullable();
            $table->longText('scope_text')->nullable();
            $table->longText('assumptions_text')->nullable();
            $table->longText('exclusions_text')->nullable();
            $table->longText('next_steps_text')->nullable();
            $table->longText('internal_note')->nullable();
            $table->date('expires_at')->nullable();
            $table->decimal('subtotal_ex_vat', 12, 2)->default(0);
            $table->decimal('discount_total_ex_vat', 12, 2)->default(0);
            $table->decimal('vat_total', 12, 2)->default(0);
            $table->decimal('total_ex_vat', 12, 2)->default(0);
            $table->decimal('total_inc_vat', 12, 2)->default(0);
            $table->decimal('margin_amount', 12, 2)->default(0);
            $table->decimal('margin_percent', 8, 2)->default(0);
            $table->json('snapshots')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by_name')->nullable();
            $table->string('accepted_ip')->nullable();
            $table->text('accepted_ua')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->unique(['quote_id', 'version_number']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('sales_quote_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_version_id')->constrained('sales_quote_versions')->cascadeOnDelete();
            $table->string('section')->default('one_time_costs');
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source_type')->default('custom');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('downstream_type')->default('one_time_order');
            $table->boolean('is_optional')->default(false);
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('unit_cost_ex_vat', 12, 2)->default(0);
            $table->decimal('unit_price_ex_vat', 12, 2)->default(0);
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->string('discount_type')->default('amount');
            $table->decimal('vat_rate', 8, 2)->nullable();
            $table->decimal('line_total_ex_vat', 12, 2)->default(0);
            $table->decimal('vat_amount', 12, 2)->default(0);
            $table->decimal('line_total_inc_vat', 12, 2)->default(0);
            $table->decimal('margin_amount', 12, 2)->default(0);
            $table->decimal('margin_percent', 8, 2)->default(0);
            $table->json('snapshot')->nullable();
            $table->timestamps();

            $table->index(['quote_version_id', 'section']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_lines');
        Schema::dropIfExists('sales_quote_versions');
        Schema::dropIfExists('sales_quotes');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_opportunity_stakeholders');
        Schema::dropIfExists('sales_opportunities');
        Schema::dropIfExists('sales_settings');
    }
};
