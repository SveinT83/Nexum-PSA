<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_quote_templates')) {
            Schema::create('sales_quote_templates', function (Blueprint $table): void {
                $table->id();
                $table->string('template_key')->unique();
                $table->string('name');
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->string('target_type')->nullable()->index();
                $table->string('customer_segment')->nullable();
                $table->longText('intro_text')->nullable();
                $table->longText('scope_text')->nullable();
                $table->longText('assumptions_text')->nullable();
                $table->longText('exclusions_text')->nullable();
                $table->longText('next_steps_text')->nullable();
                $table->json('seller_checklist')->nullable();
                $table->json('approval_policy_hints')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('sales_quote_template_option_groups')) {
            Schema::create('sales_quote_template_option_groups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('sales_quote_templates')->cascadeOnDelete();
                $table->string('name');
                $table->string('type')->default('optional')->index();
                $table->text('description')->nullable();
                $table->unsignedInteger('min_select')->default(0);
                $table->unsignedInteger('max_select')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['template_id', 'sort_order'], 'sq_template_groups_sort_idx');
            });
        }

        if (! Schema::hasTable('sales_quote_template_lines')) {
            Schema::create('sales_quote_template_lines', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('sales_quote_templates')->cascadeOnDelete();
                $table->foreignId('template_option_group_id')->nullable()->constrained('sales_quote_template_option_groups')->nullOnDelete();
                $table->string('section')->default('one_time_costs');
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('source_type')->default('custom');
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('downstream_type')->default('one_time_order');
                $table->string('billing_cadence')->default('one_time');
                $table->boolean('is_required')->default(true)->index();
                $table->boolean('is_recommended')->default(false);
                $table->boolean('customer_selected_by_default')->default(true);
                $table->boolean('customer_quantity_editable')->default(false);
                $table->decimal('min_customer_quantity', 12, 2)->default(1);
                $table->decimal('max_customer_quantity', 12, 2)->nullable();
                $table->string('customer_label')->nullable();
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
                $table->json('source_snapshot')->nullable();
                $table->timestamps();

                $table->index(['template_id', 'section'], 'sq_template_lines_section_idx');
                $table->index(['source_type', 'source_id'], 'sq_template_lines_source_idx');
            });
        }

        if (! Schema::hasTable('sales_quote_template_acknowledgements')) {
            Schema::create('sales_quote_template_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('template_id')->constrained('sales_quote_templates')->cascadeOnDelete();
                $table->foreignId('template_line_id')->nullable()->constrained('sales_quote_template_lines')->cascadeOnDelete();
                $table->string('title');
                $table->longText('body');
                $table->boolean('is_required')->default(true);
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['template_id', 'sort_order'], 'sq_template_ack_sort_idx');
            });
        }

        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_quote_versions', 'source_template_id')) {
                $table->foreignId('source_template_id')->nullable()->after('quote_id')->constrained('sales_quote_templates')->nullOnDelete();
            }

            if (! Schema::hasColumn('sales_quote_versions', 'template_snapshot')) {
                $table->json('template_snapshot')->nullable()->after('snapshots');
            }
        });

        Schema::table('sales_quote_conversion_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_quote_conversion_plans', 'target_reference')) {
                $table->string('target_reference')->nullable()->after('created_record_id');
            }

            if (! Schema::hasColumn('sales_quote_conversion_plans', 'operator_note')) {
                $table->text('operator_note')->nullable()->after('target_reference');
            }

            if (! Schema::hasColumn('sales_quote_conversion_plans', 'processed_by')) {
                $table->foreignId('processed_by')->nullable()->after('operator_note')->constrained('user_management')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_quote_conversion_plans', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_quote_conversion_plans', 'processed_by')) {
                $table->dropConstrainedForeignId('processed_by');
            }

            foreach (['operator_note', 'target_reference'] as $column) {
                if (Schema::hasColumn('sales_quote_conversion_plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            if (Schema::hasColumn('sales_quote_versions', 'source_template_id')) {
                $table->dropConstrainedForeignId('source_template_id');
            }

            if (Schema::hasColumn('sales_quote_versions', 'template_snapshot')) {
                $table->dropColumn('template_snapshot');
            }
        });

        Schema::dropIfExists('sales_quote_template_acknowledgements');
        Schema::dropIfExists('sales_quote_template_lines');
        Schema::dropIfExists('sales_quote_template_option_groups');
        Schema::dropIfExists('sales_quote_templates');
    }
};
