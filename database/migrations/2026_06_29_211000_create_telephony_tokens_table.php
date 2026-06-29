<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = env('AUTH_USER_TABLE', 'user_management');

        Schema::create('telephony_tokens', function (Blueprint $table) use ($userTable): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('token_hash', 64)->unique();
            $table->text('token_value');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telephony_tokens');
    }
};
