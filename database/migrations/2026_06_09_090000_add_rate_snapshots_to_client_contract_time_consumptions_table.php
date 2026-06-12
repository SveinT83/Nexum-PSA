<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contract_time_consumptions', function (Blueprint $table): void {
            if (! Schema::hasColumn('client_contract_time_consumptions', 'contract_item_time_rate_id')) {
                $table->unsignedBigInteger('contract_item_time_rate_id')->nullable()->after('contract_item_id');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'time_rate_id')) {
                $table->unsignedBigInteger('time_rate_id')->nullable()->after('contract_item_time_rate_id');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_name')) {
                $table->string('rate_name')->nullable()->after('source');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_code')) {
                $table->string('rate_code')->nullable()->after('rate_name');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_type')) {
                $table->string('rate_type')->nullable()->after('rate_code');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_unit')) {
                $table->string('rate_unit')->nullable()->after('rate_type');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_amount_ex_vat')) {
                $table->decimal('rate_amount_ex_vat', 12, 2)->nullable()->after('rate_unit');
            }
            if (! Schema::hasColumn('client_contract_time_consumptions', 'rate_currency')) {
                $table->string('rate_currency', 3)->default('NOK')->after('rate_amount_ex_vat');
            }
        });

        Schema::table('client_contract_time_consumptions', function (Blueprint $table): void {
            if (! $this->foreignKeyExists('client_contract_time_consumptions', 'cctc_item_rate_fk')) {
                $table->foreign('contract_item_time_rate_id', 'cctc_item_rate_fk')
                    ->references('id')
                    ->on('contract_item_time_rates')
                    ->nullOnDelete();
            }
            if (! $this->foreignKeyExists('client_contract_time_consumptions', 'cctc_time_rate_fk')) {
                $table->foreign('time_rate_id', 'cctc_time_rate_fk')
                    ->references('id')
                    ->on('time_rates')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_contract_time_consumptions', function (Blueprint $table): void {
            if ($this->foreignKeyExists('client_contract_time_consumptions', 'cctc_item_rate_fk')) {
                $table->dropForeign('cctc_item_rate_fk');
            }
            if ($this->foreignKeyExists('client_contract_time_consumptions', 'cctc_time_rate_fk')) {
                $table->dropForeign('cctc_time_rate_fk');
            }
        });

        Schema::table('client_contract_time_consumptions', function (Blueprint $table): void {
            $columns = collect([
                'contract_item_time_rate_id',
                'time_rate_id',
                'rate_name',
                'rate_code',
                'rate_type',
                'rate_unit',
                'rate_amount_ex_vat',
                'rate_currency',
            ])->filter(fn (string $column): bool => Schema::hasColumn('client_contract_time_consumptions', $column))->all();

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->exists();
    }
};
