<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A refreshed token never follows a later hostname/account/credential change silently. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nextcloud_talk_destinations', function (Blueprint $table) {
            $table->string('provider_binding', 80)->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('nextcloud_talk_destinations')->whereNotNull('provider_binding')->exists()) {
            throw new RuntimeException('Reconcile Talk destination bindings before removing this guard.');
        }
        Schema::table('nextcloud_talk_destinations', fn (Blueprint $table) => $table->dropColumn('provider_binding'));
    }
};
