<?php

namespace App\Modules\Economy\Support;

use App\Modules\Economy\Models\EconomyOrderLine;
use Illuminate\Support\Facades\DB;

/** Preserve recorded financial ownership through source edits, Ticket moves and line regeneration. */
class EconomySourceOwner
{
    public static function client(string $type, int $id, int $candidate): int
    {
        $owner = DB::table('economy_source_owners')->where('source_type', $type)->where('source_id', $id)->first();
        if ($owner) {
            return (int) $owner->client_id;
        }
        $recorded = EconomyOrderLine::query()->where('source_type', $type)->where('source_id', $id)->value('client_id');
        $client = $recorded ? (int) $recorded : $candidate;
        DB::table('economy_source_owners')->insert(['source_type' => $type, 'source_id' => $id, 'client_id' => $client, 'created_at' => now()]);

        return $client;
    }
}
