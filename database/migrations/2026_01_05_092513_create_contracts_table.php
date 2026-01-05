<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->string('description');
            $table->boolean('approval_status');
            $table->string('approval_sent_at');
            $table->string('approval_expires_at');
            $table->dateTime('approval_approved_at');
            $table->string('approval_approved_by');
            $table->text('approval_metadata');
            $table->date('start_date');
            $table->string('end_date');
            $table->string('binding_end_date');
            $table->string('auto_renew');
            $table->string('renewal_months');
            $table->boolean('allow_indexing_during_binding');
            $table->string('max_index_pct_binding');
            $table->string('post_binding_index_pct');
            $table->boolean('allow_decrease_during_binding');
            $table->string('billing_interval');
            $table->string('total_monthly_amount');
            $table->timestamp('last_indexed_at');
            $table->string('created_by');
            $table->text('services');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
