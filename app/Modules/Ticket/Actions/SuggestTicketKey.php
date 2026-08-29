<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Allocates a Ticket key from the current year's locked sequence row. */
class SuggestTicketKey
{
    public function handle(): string
    {
        $year = (int) now()->format('Y');
        $prefix = 'TD-'.$year.'-';

        return DB::transaction(function () use ($year, $prefix): string {
            $timestamp = now();
            // ON DUPLICATE KEY UPDATE takes an exclusive InnoDB record lock.
            // This avoids the shared-to-exclusive upgrade deadlock that an
            // INSERT IGNORE followed by SELECT FOR UPDATE can create.
            DB::table('ticket_key_sequences')->upsert(
                [[
                    'year' => $year,
                    'next_sequence' => 1,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]],
                ['year'],
                ['updated_at'],
            );

            $sequence = DB::table('ticket_key_sequences')
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $sequence) {
                throw new RuntimeException('Nexum could not lock the Ticket key sequence.');
            }

            $next = max(1, (int) $sequence->next_sequence);
            do {
                $key = $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
                $occupied = Ticket::withTrashed()
                    ->where('ticket_key', $key)
                    ->lockForUpdate()
                    ->first(['id']) !== null;

                if ($occupied) {
                    $next++;
                }
            } while ($occupied);

            DB::table('ticket_key_sequences')
                ->where('year', $year)
                ->update([
                    'next_sequence' => $next + 1,
                    'updated_at' => now(),
                ]);

            return $key;
        });
    }
}
