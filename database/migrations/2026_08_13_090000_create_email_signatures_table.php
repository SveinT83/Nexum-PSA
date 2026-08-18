<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('email_signatures')) {
            return;
        }

        Schema::create('email_signatures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('user_management')
                ->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->text('body_html')->nullable();
            $table->text('body_text')->nullable();
            $table->boolean('use_on_compose')->default(true);
            $table->boolean('use_on_reply')->default(true);
            $table->boolean('use_on_reply_all')->default(true);
            $table->boolean('use_on_forward')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('user_management')->nullOnDelete();
            $table->timestamps();

            $table->unique('user_id', 'email_signatures_user_unique');
            $table->index(['user_id', 'use_on_compose'], 'email_signatures_compose_index');
            $table->index(['user_id', 'use_on_reply'], 'email_signatures_reply_index');
            $table->index(['user_id', 'use_on_forward'], 'email_signatures_forward_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_signatures');
    }
};
