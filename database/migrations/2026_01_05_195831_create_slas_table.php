<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sla', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description');
            $table->boolean('is_default')->default(false)->index();
            $table->integer('low_firstResponse');
            $table->string('low_firstResponse_type');
            $table->integer('low_onsite');
            $table->string('low_onsite_type');
            $table->integer('medium_firstResponse');
            $table->string('medium_firstResponse_type');
            $table->integer('medium_onsite');
            $table->string('medium_onsite_type');
            $table->integer('high_firstResponse');
            $table->string('high_firstResponse_type');
            $table->integer('high_onsite');
            $table->string('high_onsite_type');
            $table->integer('created_by_user_id');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->foreign('sla_id')->references('id')->on('sla')->nullOnDelete();
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->foreign('sla_id')->references('id')->on('sla')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->dropForeign(['sla_id']);
        });

        Schema::table('contracts', function (Blueprint $table): void {
            $table->dropForeign(['sla_id']);
        });

        Schema::dropIfExists('sla');
    }
};
