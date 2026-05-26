<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_formats', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('code', 50)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('client_formats')->insert([
            [
                'name' => 'Limited Company',
                'code' => 'AS',
                'description' => 'Norwegian aksjeselskap.',
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Sole Proprietorship',
                'code' => 'ENK',
                'description' => 'Norwegian enkeltpersonforetak.',
                'is_active' => true,
                'sort_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Private Individual',
                'code' => 'PRIVATE',
                'description' => 'Private person without an organization number requirement.',
                'is_active' => true,
                'sort_order' => 30,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'client_format_id')) {
                $table->foreignId('client_format_id')
                    ->nullable()
                    ->after('org_no')
                    ->constrained('client_formats')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (Schema::hasColumn('clients', 'client_format_id')) {
                $table->dropConstrainedForeignId('client_format_id');
            }
        });

        Schema::dropIfExists('client_formats');
    }
};
