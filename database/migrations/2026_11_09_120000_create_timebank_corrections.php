<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Corrections retain source identities; no cascade may erase commercial evidence. */
    public function up(): void
    {
        Schema::create('commercial_timebank_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $t->unsignedBigInteger('actor_id');
            $t->string('kind', 20);
            $t->string('source_type', 20)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('quick_consumption_id')->nullable()->constrained('client_contract_time_consumptions')->restrictOnDelete();
            $t->foreignId('ticket_time_entry_id')->nullable()->constrained('ticket_time_entries')->restrictOnDelete();
            $t->string('reversal_key', 100)->nullable()->unique();
            $t->text('reason');
            $t->date('effective_date');
            $t->json('snapshot');
            $t->timestamp('created_at');
        });
        Schema::create('commercial_timebank_effects', function (Blueprint $t) {
            $t->id();
            $t->foreignId('correction_id')->constrained('commercial_timebank_corrections')->restrictOnDelete();
            $t->foreignId('contract_item_id')->nullable()->constrained('contract_items')->restrictOnDelete();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->integer('adjustment_minutes')->default(0);
            $t->integer('reversed_usage_minutes')->default(0);
            $t->integer('reversed_covered_minutes')->default(0);
            $t->integer('reversed_billable_minutes')->default(0);
        });
        // Sequence snapshots order legacy/pending work and adjustments within the same day.
        foreach (['ticket_time_entries', 'client_contract_time_consumptions'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->unsignedBigInteger('timebank_ledger_sequence')->default(0));
        }
        foreach (['commercial.timebank.adjust', 'commercial.timebank.reverse'] as $name) {
            \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (DB::table('commercial_timebank_corrections')->exists()) {
            throw new RuntimeException('Retain correction evidence; disable writes and use a compatible build.');
        }
        foreach (['ticket_time_entries', 'client_contract_time_consumptions'] as $name) {
            Schema::table($name, fn (Blueprint $t) => $t->dropColumn('timebank_ledger_sequence'));
        }
        Schema::dropIfExists('commercial_timebank_effects');
        Schema::dropIfExists('commercial_timebank_corrections');
    }
};
