<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_folder_navigation_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('email_folder_id');
            $table->boolean('is_expanded');
            $table->timestamps();

            $table->foreign('user_id', 'email_folder_nav_user_fk')
                ->references('id')->on('user_management')->cascadeOnDelete();
            $table->foreign('email_folder_id', 'email_folder_nav_folder_fk')
                ->references('id')->on('email_folders')->cascadeOnDelete();
            $table->unique(
                ['user_id', 'email_folder_id'],
                'email_folder_nav_user_folder_uq',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_folder_navigation_preferences');
    }
};
