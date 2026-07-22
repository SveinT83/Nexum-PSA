<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table): void {
            $table->foreignUuid('source_integration_id')->nullable()->after('source')
                ->constrained('integrations')->nullOnDelete();
            $table->boolean('managed_externally')->default(false)
                ->after('source_integration_id')->index();
        });

        Schema::table('costs', function (Blueprint $table): void {
            $table->foreignUuid('source_integration_id')->nullable()->after('source')
                ->constrained('integrations')->nullOnDelete();
        });

        // Backfill existing provider records without depending on a specific database engine.
        DB::table('cloudfactory_offers')
            ->select(['integration_id', 'service_id', 'cost_id'])
            ->where(function ($query): void {
                $query->whereNotNull('service_id')->orWhereNotNull('cost_id');
            })
            ->orderBy('id')
            ->get()
            ->each(function (object $offer): void {
                if ($offer->service_id) {
                    DB::table('services')->where('id', $offer->service_id)->update([
                        'source_integration_id' => $offer->integration_id,
                        'managed_externally' => true,
                    ]);
                }

                if ($offer->cost_id) {
                    DB::table('costs')->where('id', $offer->cost_id)->update([
                        'source_integration_id' => $offer->integration_id,
                        'managed_externally' => true,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('costs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_integration_id');
        });

        Schema::table('services', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_integration_id');
            $table->dropColumn('managed_externally');
        });
    }
};
