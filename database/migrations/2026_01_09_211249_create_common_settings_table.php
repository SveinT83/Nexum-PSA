<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('common_settings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('value')->nullable();
            $table->text('json')->nullable();
        });

        DB::table('common_settings')->insert([
            [
                'name' => 'enforce_two_factor',
                'type' => 'security',
                'description' => 'Require two-factor authentication for selected roles.',
                'value' => '0',
                'json' => null,
            ],
            [
                'name' => 'enforce_two_factor_roles',
                'type' => 'security',
                'description' => 'Role names that must use two-factor authentication when enforcement is enabled.',
                'value' => null,
                'json' => json_encode([]),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('common_settings');
    }
};
