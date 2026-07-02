<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_contexts', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 40)->index();
            $table->foreignId('client_id')->nullable()->constrained('clients')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_default')->default(false)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['type', 'client_id'], 'work_contexts_type_client_unique');
            $table->index(['type', 'is_default'], 'work_contexts_type_default_idx');
        });

        $now = now();

        DB::table('work_contexts')->insert([
            'type' => 'internal',
            'client_id' => null,
            'name' => 'Own organization',
            'is_default' => true,
            'metadata' => json_encode(['source' => 'migration']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('clients')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(500, function ($clients) use ($now): void {
                $rows = $clients->map(fn ($client) => [
                    'type' => 'client',
                    'client_id' => $client->id,
                    'name' => $client->name,
                    'is_default' => false,
                    'metadata' => json_encode(['source' => 'migration']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($rows !== []) {
                    DB::table('work_contexts')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_contexts');
    }
};
