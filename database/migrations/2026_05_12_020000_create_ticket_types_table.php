<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_deletable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        $supportTypeId = DB::table('ticket_types')->insertGetId([
            'name' => 'Support',
            'slug' => 'support',
            'description' => 'Default support ticket type.',
            'is_system' => true,
            'is_deletable' => false,
            'is_active' => true,
            'sort_order' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ticket_types')->insert([
            'name' => 'Lead',
            'slug' => 'lead',
            'description' => 'Default lead or sales inquiry ticket type.',
            'is_system' => true,
            'is_deletable' => true,
            'is_active' => true,
            'sort_order' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (Schema::hasTable('tickets') && ! Schema::hasColumn('tickets', 'ticket_type_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('ticket_type_id')->nullable()->after('type')->constrained('ticket_types')->nullOnDelete();
            });

            DB::table('tickets')->update(['ticket_type_id' => $supportTypeId]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tickets') && Schema::hasColumn('tickets', 'ticket_type_id')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('ticket_type_id');
            });
        }

        Schema::dropIfExists('ticket_types');
    }
};
