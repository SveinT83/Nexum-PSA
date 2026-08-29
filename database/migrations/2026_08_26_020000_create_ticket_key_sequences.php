<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_key_sequences', function (Blueprint $table): void {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedBigInteger('next_sequence');
            $table->timestamps();
        });

        $maximumByYear = [];
        DB::table('tickets')
            ->select(['id', 'ticket_key'])
            ->orderBy('id')
            ->chunkById(500, function ($tickets) use (&$maximumByYear): void {
                foreach ($tickets as $ticket) {
                    if (preg_match('/\ATD-([0-9]{4})-([0-9]+)\z/', (string) $ticket->ticket_key, $matches) !== 1) {
                        continue;
                    }

                    $year = (int) $matches[1];
                    $sequence = (int) $matches[2];
                    $maximumByYear[$year] = max($maximumByYear[$year] ?? 0, $sequence);
                }
            });

        $timestamp = now();
        foreach ($maximumByYear as $year => $maximum) {
            DB::table('ticket_key_sequences')->insert([
                'year' => $year,
                'next_sequence' => $maximum + 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_key_sequences');
    }
};
