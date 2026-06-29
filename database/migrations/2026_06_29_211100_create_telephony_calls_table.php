<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = env('AUTH_USER_TABLE', 'user_management');

        Schema::create('telephony_calls', function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->string('provider_profile', 100)->default('generic')->index();
            $table->string('provider_call_id')->nullable();
            $table->string('provider_call_key', 64)->nullable()->unique();
            $table->string('fallback_fingerprint', 64)->nullable()->unique();
            $table->string('direction', 50)->nullable()->index();
            $table->string('caller_number_raw')->nullable();
            $table->string('caller_number_normalized', 50)->nullable()->index();
            $table->string('called_number')->nullable();
            $table->unsignedBigInteger('answered_by_user_id')->index();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('client_user_id')->nullable()->constrained('client_users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('client_sites')->nullOnDelete();
            $table->foreignId('linked_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('answered_at')->nullable()->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->string('status', 50)->default('open')->index();
            $table->longText('notes')->nullable();
            $table->boolean('is_test')->default(false)->index();
            $table->json('raw_payload')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('answered_by_user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();

            $table->index(['answered_by_user_id', 'status']);
            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_calls');
    }
};
