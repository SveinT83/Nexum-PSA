<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();

            // Relations
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('created_by');

            // Basic info
            $table->string('description')->nullable();

            // Approval flow
            $table->enum('approval_status', ['draft', 'pending', 'approved', 'rejected'])->default('draft');
            $table->timestamp('approval_sent_at')->nullable();
            $table->timestamp('approval_expires_at')->nullable();
            $table->timestamp('approval_approved_at')->nullable();
            $table->unsignedBigInteger('approval_approved_by')->nullable();
            $table->json('approval_metadata')->nullable();

            // Contract period
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('binding_end_date')->nullable();

            // Renewal
            $table->boolean('auto_renew')->default(false);
            $table->integer('renewal_months')->nullable();

            // Indexing policy
            $table->boolean('allow_indexing_during_binding')->default(false);
            $table->decimal('max_index_pct_binding', 5, 2)->nullable();
            $table->decimal('post_binding_index_pct', 5, 2)->nullable();
            $table->boolean('allow_decrease_during_binding')->default(false);

            // Financials
            $table->decimal('total_monthly_amount', 12, 2)->default(0);
            $table->timestamp('last_indexed_at')->nullable();

            // Meta
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index('client_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
