<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('type')->default('manual');
            $table->date('work_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->default(0);
            $table->string('cost_account')->nullable();
            $table->text('note')->nullable();
            $table->boolean('billable')->default(true);
            $table->string('billing_status')->default('pending');
            $table->string('timebank_status')->default('pending');
            $table->string('billing_basis')->nullable();
            $table->text('invoice_text')->nullable();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('contract_item_id')->nullable()->constrained('contract_items')->nullOnDelete();
            $table->unsignedBigInteger('contract_item_time_rate_id')->nullable()->index();
            $table->unsignedBigInteger('time_rate_id')->nullable()->index();
            $table->string('rate_name')->nullable();
            $table->string('rate_code')->nullable();
            $table->string('rate_type')->nullable();
            $table->string('rate_unit')->nullable();
            $table->decimal('rate_amount_ex_vat', 12, 2)->nullable();
            $table->string('rate_currency', 3)->default('NOK');
            $table->timestamps();

            $table->index(['ticket_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_time_entries');
    }
};
