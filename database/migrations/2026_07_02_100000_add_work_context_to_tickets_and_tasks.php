<?php

use App\Modules\WorkContext\Actions\EnsureWorkContextDefaults;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('work_context_id')->nullable()->after('client_id')->constrained('work_contexts')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('work_context_id')->nullable()->after('client_id')->constrained('work_contexts')->nullOnDelete();
        });

        app(EnsureWorkContextDefaults::class)->handle();

        DB::table('work_contexts')
            ->where('type', 'client')
            ->whereNotNull('client_id')
            ->orderBy('id')
            ->get(['id', 'client_id'])
            ->each(function (object $context): void {
                DB::table('tickets')
                    ->where('client_id', $context->client_id)
                    ->whereNull('work_context_id')
                    ->update(['work_context_id' => $context->id]);

                DB::table('tasks')
                    ->where('client_id', $context->client_id)
                    ->whereNull('work_context_id')
                    ->update(['work_context_id' => $context->id]);
            });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_context_id');
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('work_context_id');
        });
    }
};
