<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $internalContextId = $this->ensureInternalContext();
        $clientContextIds = $this->ensureClientContexts();

        if (Schema::hasTable('documentations') && ! Schema::hasColumn('documentations', 'work_context_id')) {
            Schema::table('documentations', function (Blueprint $table): void {
                $table->foreignId('work_context_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('work_contexts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('risk_assessments') && ! Schema::hasColumn('risk_assessments', 'work_context_id')) {
            Schema::table('risk_assessments', function (Blueprint $table): void {
                $table->foreignId('work_context_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('work_contexts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('calendar_events') && ! Schema::hasColumn('calendar_events', 'work_context_id')) {
            Schema::table('calendar_events', function (Blueprint $table): void {
                $table->foreignId('work_context_id')
                    ->nullable()
                    ->after('calendar_id')
                    ->constrained('work_contexts')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('assets')) {
            if (Schema::hasColumn('assets', 'client_id')) {
                Schema::table('assets', function (Blueprint $table): void {
                    $table->foreignId('client_id')->nullable()->change();
                });
            }

            if (! Schema::hasColumn('assets', 'work_context_id')) {
                Schema::table('assets', function (Blueprint $table): void {
                    $table->foreignId('work_context_id')
                        ->nullable()
                        ->after('client_id')
                        ->constrained('work_contexts')
                        ->nullOnDelete();
                });
            }
        }

        $this->backfillClientContext('documentations', $clientContextIds);
        DB::table('documentations')
            ->where('scope_type', 'internal')
            ->whereNull('client_id')
            ->whereNull('site_id')
            ->whereNull('work_context_id')
            ->update(['work_context_id' => $internalContextId]);

        $this->backfillClientContext('risk_assessments', $clientContextIds);
        DB::table('risk_assessments')
            ->whereNull('client_id')
            ->whereNull('work_context_id')
            ->update(['work_context_id' => $internalContextId]);

        DB::table('calendar_events')
            ->whereNull('work_context_id')
            ->update(['work_context_id' => $internalContextId]);

        $this->backfillClientContext('assets', $clientContextIds);
    }

    public function down(): void
    {
        foreach (['assets', 'calendar_events', 'risk_assessments', 'documentations'] as $tableName) {
            if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'work_context_id')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->dropConstrainedForeignId('work_context_id');
                });
            }
        }

        if (Schema::hasTable('assets') && Schema::hasColumn('assets', 'client_id')) {
            Schema::table('assets', function (Blueprint $table): void {
                $table->foreignId('client_id')->nullable(false)->change();
            });
        }
    }

    private function ensureInternalContext(): int
    {
        $contextId = DB::table('work_contexts')
            ->where('type', 'internal')
            ->where('is_default', true)
            ->value('id');

        if ($contextId) {
            return (int) $contextId;
        }

        return (int) DB::table('work_contexts')->insertGetId([
            'type' => 'internal',
            'client_id' => null,
            'name' => 'Own organization',
            'is_default' => true,
            'metadata' => json_encode(['source' => 'module_adoption_migration']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, int>
     */
    private function ensureClientContexts(): Collection
    {
        DB::table('clients')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->each(function (object $client): void {
                $now = now();
                $context = DB::table('work_contexts')
                    ->where('type', 'client')
                    ->where('client_id', $client->id);

                if ($context->exists()) {
                    $context->update([
                        'name' => $client->name,
                        'is_default' => false,
                        'metadata' => json_encode(['source' => 'module_adoption_migration']),
                        'updated_at' => $now,
                    ]);

                    return;
                }

                DB::table('work_contexts')->insert([
                    'type' => 'client',
                    'client_id' => $client->id,
                    'name' => $client->name,
                    'is_default' => false,
                    'metadata' => json_encode(['source' => 'module_adoption_migration']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });

        return DB::table('work_contexts')
            ->where('type', 'client')
            ->whereNotNull('client_id')
            ->pluck('id', 'client_id');
    }

    /**
     * @param Collection<int, int> $clientContextIds
     */
    private function backfillClientContext(string $tableName, Collection $clientContextIds): void
    {
        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'work_context_id')) {
            return;
        }

        $clientContextIds->each(function (int $contextId, int $clientId) use ($tableName): void {
            DB::table($tableName)
                ->where('client_id', $clientId)
                ->whereNull('work_context_id')
                ->update(['work_context_id' => $contextId]);
        });
    }
};
