<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_time_entries', function (Blueprint $table): void {
            if (! Schema::hasColumn('ticket_time_entries', 'work_date')) {
                $table->date('work_date')->nullable()->after('type');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'billable')) {
                $table->boolean('billable')->default(true)->after('note');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'billing_status')) {
                $table->string('billing_status')->default('pending')->after('billable');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'timebank_status')) {
                $table->string('timebank_status')->default('pending')->after('billing_status');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'billing_basis')) {
                $table->string('billing_basis')->nullable()->after('timebank_status');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'invoice_text')) {
                $table->text('invoice_text')->nullable()->after('billing_basis');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'contract_id')) {
                $table->foreignId('contract_id')->nullable()->after('invoice_text')->constrained('contracts')->nullOnDelete();
            }

            if (! Schema::hasColumn('ticket_time_entries', 'contract_item_id')) {
                $table->foreignId('contract_item_id')->nullable()->after('contract_id')->constrained('contract_items')->nullOnDelete();
            }

            if (! Schema::hasColumn('ticket_time_entries', 'contract_item_time_rate_id')) {
                $table->foreignId('contract_item_time_rate_id')->nullable()->after('contract_item_id')->constrained('contract_item_time_rates')->nullOnDelete();
            }

            if (! Schema::hasColumn('ticket_time_entries', 'time_rate_id')) {
                $table->foreignId('time_rate_id')->nullable()->after('contract_item_time_rate_id')->constrained('time_rates')->nullOnDelete();
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_name')) {
                $table->string('rate_name')->nullable()->after('time_rate_id');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_code')) {
                $table->string('rate_code')->nullable()->after('rate_name');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_type')) {
                $table->string('rate_type')->nullable()->after('rate_code');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_unit')) {
                $table->string('rate_unit')->nullable()->after('rate_type');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_amount_ex_vat')) {
                $table->decimal('rate_amount_ex_vat', 12, 2)->nullable()->after('rate_unit');
            }

            if (! Schema::hasColumn('ticket_time_entries', 'rate_currency')) {
                $table->string('rate_currency', 3)->default('NOK')->after('rate_amount_ex_vat');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ticket_time_entries', function (Blueprint $table): void {
            foreach (['time_rate_id', 'contract_item_time_rate_id', 'contract_item_id', 'contract_id'] as $column) {
                if (Schema::hasColumn('ticket_time_entries', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach ([
                'rate_currency',
                'rate_amount_ex_vat',
                'rate_unit',
                'rate_type',
                'rate_code',
                'rate_name',
                'invoice_text',
                'billing_basis',
                'timebank_status',
                'billing_status',
                'billable',
                'work_date',
            ] as $column) {
                if (Schema::hasColumn('ticket_time_entries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
