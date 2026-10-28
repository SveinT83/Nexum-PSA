<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** No key material: serialize device registration with disable-first managed identity replacement. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_web_push_setup_mutex', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('notification_web_push_setup_mutex')->insert(['id' => 1]);
    }

    public function down(): void
    {
        throw new RuntimeException('Disable and retire managed Web Push through the deployment runbook before removing its registration mutex.');
    }
};
