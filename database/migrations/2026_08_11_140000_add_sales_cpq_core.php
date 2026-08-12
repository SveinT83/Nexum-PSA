<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_quote_versions', 'approval_status')) {
                $table->string('approval_status')->default('not_required')->after('status')->index();
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_required_reasons')) {
                $table->json('approval_required_reasons')->nullable()->after('approval_status');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_policy_snapshot')) {
                $table->json('approval_policy_snapshot')->nullable()->after('approval_required_reasons');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_requested_at')) {
                $table->timestamp('approval_requested_at')->nullable()->after('approval_policy_snapshot');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_requested_by')) {
                $table->foreignId('approval_requested_by')->nullable()->after('approval_requested_at')->constrained('user_management')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_decided_at')) {
                $table->timestamp('approval_decided_at')->nullable()->after('approval_requested_by');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_decided_by')) {
                $table->foreignId('approval_decided_by')->nullable()->after('approval_decided_at')->constrained('user_management')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_quote_versions', 'approval_decision_note')) {
                $table->text('approval_decision_note')->nullable()->after('approval_decided_by');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'declined_at')) {
                $table->timestamp('declined_at')->nullable()->after('rejected_at');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'declined_by_name')) {
                $table->string('declined_by_name')->nullable()->after('declined_at');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'declined_reason')) {
                $table->text('declined_reason')->nullable()->after('declined_by_name');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'declined_ip')) {
                $table->string('declined_ip')->nullable()->after('declined_reason');
            }
            if (! Schema::hasColumn('sales_quote_versions', 'declined_ua')) {
                $table->text('declined_ua')->nullable()->after('declined_ip');
            }
        });

        if (! Schema::hasTable('sales_quote_option_groups')) {
            Schema::create('sales_quote_option_groups', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_version_id')->constrained('sales_quote_versions')->cascadeOnDelete();
                $table->string('name');
                $table->string('type')->default('optional')->index();
                $table->text('description')->nullable();
                $table->unsignedInteger('min_select')->default(0);
                $table->unsignedInteger('max_select')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['quote_version_id', 'sort_order']);
            });
        }

        Schema::table('sales_quote_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('sales_quote_lines', 'option_group_id')) {
                $table->foreignId('option_group_id')->nullable()->after('quote_version_id')->constrained('sales_quote_option_groups')->nullOnDelete();
            }
            if (! Schema::hasColumn('sales_quote_lines', 'is_required')) {
                $table->boolean('is_required')->default(true)->after('is_optional')->index();
            }
            if (! Schema::hasColumn('sales_quote_lines', 'is_recommended')) {
                $table->boolean('is_recommended')->default(false)->after('is_required');
            }
            if (! Schema::hasColumn('sales_quote_lines', 'customer_selected_by_default')) {
                $table->boolean('customer_selected_by_default')->default(true)->after('is_recommended');
            }
            if (! Schema::hasColumn('sales_quote_lines', 'customer_quantity_editable')) {
                $table->boolean('customer_quantity_editable')->default(false)->after('customer_selected_by_default');
            }
            if (! Schema::hasColumn('sales_quote_lines', 'min_customer_quantity')) {
                $table->decimal('min_customer_quantity', 12, 2)->default(1)->after('customer_quantity_editable');
            }
            if (! Schema::hasColumn('sales_quote_lines', 'max_customer_quantity')) {
                $table->decimal('max_customer_quantity', 12, 2)->nullable()->after('min_customer_quantity');
            }
            if (! Schema::hasColumn('sales_quote_lines', 'customer_label')) {
                $table->string('customer_label')->nullable()->after('max_customer_quantity');
            }
        });

        DB::table('sales_quote_lines')->update([
            'is_required' => DB::raw('CASE WHEN is_optional = 1 THEN 0 ELSE 1 END'),
            'is_recommended' => false,
            'customer_selected_by_default' => true,
            'customer_quantity_editable' => false,
            'min_customer_quantity' => DB::raw('quantity'),
            'max_customer_quantity' => DB::raw('quantity'),
        ]);

        if (! Schema::hasTable('sales_quote_acknowledgements')) {
            Schema::create('sales_quote_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_version_id')->constrained('sales_quote_versions')->cascadeOnDelete();
                $table->foreignId('quote_line_id')->nullable()->constrained('sales_quote_lines')->cascadeOnDelete();
                $table->string('title');
                $table->longText('body');
                $table->boolean('is_required')->default(true);
                $table->string('source_type')->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['quote_version_id', 'sort_order']);
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('sales_quote_acceptance_snapshots')) {
            Schema::create('sales_quote_acceptance_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_version_id')->unique()->constrained('sales_quote_versions')->cascadeOnDelete();
                $table->timestamp('accepted_at');
                $table->string('accepted_by_name');
                $table->string('accepted_by_email')->nullable();
                $table->string('source_method')->default('public_link');
                $table->foreignId('source_user_id')->nullable()->constrained('user_management')->nullOnDelete();
                $table->foreignId('portal_account_id')->nullable()->constrained('customer_portal_accounts')->nullOnDelete();
                $table->foreignId('portal_membership_id')->nullable()->constrained('customer_portal_memberships')->nullOnDelete();
                $table->foreignId('portal_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->json('selected_line_ids');
                $table->json('declined_line_ids')->nullable();
                $table->json('selected_lines');
                $table->json('declined_lines')->nullable();
                $table->json('acknowledgements')->nullable();
                $table->json('totals');
                $table->json('customer_identity');
                $table->json('public_text_snapshot');
                $table->json('selection_payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sales_quote_conversion_plans')) {
            Schema::create('sales_quote_conversion_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('quote_version_id')->constrained('sales_quote_versions')->cascadeOnDelete();
                $table->foreignId('acceptance_snapshot_id')->constrained('sales_quote_acceptance_snapshots')->cascadeOnDelete();
                $table->foreignId('quote_line_id')->nullable()->constrained('sales_quote_lines')->nullOnDelete();
                $table->string('target_domain')->index();
                $table->string('target_type')->index();
                $table->string('status')->default('pending')->index();
                $table->string('idempotency_key')->unique();
                $table->json('source_snapshot')->nullable();
                $table->json('accepted_line_snapshot');
                $table->nullableMorphs('created_record', 'sqcp_created_record_idx');
                $table->timestamp('processed_at')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
                $table->timestamps();

                $table->index(['quote_version_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_conversion_plans');
        Schema::dropIfExists('sales_quote_acceptance_snapshots');
        Schema::dropIfExists('sales_quote_acknowledgements');

        Schema::table('sales_quote_lines', function (Blueprint $table): void {
            foreach ([
                'option_group_id',
                'is_required',
                'is_recommended',
                'customer_selected_by_default',
                'customer_quantity_editable',
                'min_customer_quantity',
                'max_customer_quantity',
                'customer_label',
            ] as $column) {
                if (Schema::hasColumn('sales_quote_lines', $column)) {
                    $column === 'option_group_id'
                        ? $table->dropConstrainedForeignId($column)
                        : $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('sales_quote_option_groups');

        Schema::table('sales_quote_versions', function (Blueprint $table): void {
            foreach ([
                'approval_requested_by',
                'approval_decided_by',
            ] as $column) {
                if (Schema::hasColumn('sales_quote_versions', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach ([
                'approval_status',
                'approval_required_reasons',
                'approval_policy_snapshot',
                'approval_requested_at',
                'approval_decided_at',
                'approval_decision_note',
                'declined_at',
                'declined_by_name',
                'declined_reason',
                'declined_ip',
                'declined_ua',
            ] as $column) {
                if (Schema::hasColumn('sales_quote_versions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
