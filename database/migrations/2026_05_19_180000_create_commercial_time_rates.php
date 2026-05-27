<?php

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_rates')) {
            Schema::create('time_rates', function (Blueprint $table): void {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->string('code')->unique();
                $table->string('rate_type')->default('labor');
                $table->string('unit')->default('hour');
                $table->decimal('amount_ex_vat', 12, 2);
                $table->string('currency', 3)->default('NOK');
                $table->text('description')->nullable();
                $table->boolean('applies_without_contract')->default(false);
                $table->boolean('applies_with_contract')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['rate_type', 'is_active']);
            });
        }

        if (! Schema::hasTable('service_time_rates')) {
            Schema::create('service_time_rates', function (Blueprint $table): void {
                $table->id();
                $table->foreignIdFor(Services::class, 'service_id')->constrained('services')->cascadeOnDelete();
                $table->foreignId('time_rate_id')->constrained('time_rates')->cascadeOnDelete();
                $table->decimal('amount_ex_vat', 12, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['service_id', 'time_rate_id'], 'service_time_rates_unique');
            });
        }

        if (! Schema::hasTable('contract_item_time_rates')) {
            Schema::create('contract_item_time_rates', function (Blueprint $table): void {
                $table->id();
                $table->foreignIdFor(ContractItem::class, 'contract_item_id')->constrained('contract_items')->cascadeOnDelete();
                $table->foreignId('time_rate_id')->nullable()->constrained('time_rates')->nullOnDelete();
                $table->foreignId('service_time_rate_id')->nullable()->constrained('service_time_rates')->nullOnDelete();
                $table->string('name');
                $table->string('code')->nullable();
                $table->string('rate_type')->default('labor');
                $table->string('unit')->default('hour');
                $table->decimal('amount_ex_vat', 12, 2);
                $table->string('currency', 3)->default('NOK');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['contract_item_id', 'is_active'], 'contract_item_time_rates_lookup_idx');
            });
        }

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_item_time_rates');
        Schema::dropIfExists('service_time_rates');
        Schema::dropIfExists('time_rates');
    }

    private function seedDefaults(): void
    {
        if (! Schema::hasTable('time_rates')) {
            return;
        }

        $now = now();
        $defaults = [
            [
                'name' => 'Time without contract',
                'code' => 'TIME_WITHOUT_CONTRACT',
                'rate_type' => 'labor',
                'unit' => 'hour',
                'amount_ex_vat' => 1200,
                'applies_without_contract' => true,
                'applies_with_contract' => false,
                'sort_order' => 10,
            ],
            [
                'name' => 'Time with contract',
                'code' => 'TIME_WITH_CONTRACT',
                'rate_type' => 'labor',
                'unit' => 'hour',
                'amount_ex_vat' => 650,
                'applies_without_contract' => false,
                'applies_with_contract' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Driving',
                'code' => 'DRIVING',
                'rate_type' => 'driving',
                'unit' => 'hour',
                'amount_ex_vat' => 520,
                'applies_without_contract' => true,
                'applies_with_contract' => true,
                'sort_order' => 30,
            ],
        ];

        foreach ($defaults as $rate) {
            DB::table('time_rates')->updateOrInsert(
                ['code' => $rate['code']],
                array_merge($rate, [
                    'slug' => Str::slug($rate['code']),
                    'currency' => 'NOK',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
};
