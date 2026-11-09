<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\TimebankCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Single read boundary shared by balances, writers, reporting and regeneration guards. */
class TimebankLedger
{
    public static function installed(): bool
    {
        return Schema::hasTable('commercial_timebank_corrections') && Schema::hasTable('commercial_timebank_effects');
    }

    public static function reversal(string $type, int $id): ?TimebankCorrection
    {
        return self::installed() ? TimebankCorrection::query()->where('reversal_key', $type.':'.$id)->first() : null;
    }

    public static function excludeReversed($query, string $type, ?string $idColumn = null)
    {
        if (! self::installed()) {
            return $query;
        }
        $idColumn ??= $query->getModel()->getTable().'.id';

        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')
            ->from('commercial_timebank_corrections as correction')
            ->where('correction.source_type', $type)->where('correction.kind', 'reversal')
            ->whereColumn('correction.source_id', $idColumn));
    }

    public static function adjustments(int $item, string $start, string $end, ?string $asOf = null, ?int $sourceSequence = null): int
    {
        if (! self::installed()) {
            return 0;
        }

        return (int) DB::table('commercial_timebank_effects as e')
            ->join('commercial_timebank_corrections as c', 'c.id', '=', 'e.correction_id')
            ->where('e.contract_item_id', $item)->where('e.period_start', $start)->where('e.period_end', $end)
            ->when($asOf, fn ($q) => $q->whereDate('c.effective_date', '<=', $asOf))
            ->when($asOf && $sourceSequence !== null, fn ($q) => $q->where(fn ($q) => $q->whereDate('c.effective_date', '<', $asOf)
                ->orWhere('c.id', '<=', $sourceSequence)))
            ->sum('e.adjustment_minutes');
    }

    /** Pre-migration source writers remain compatible during a rolling source installation. */
    public static function sourceSnapshot(string $table): array
    {
        return Schema::hasColumn($table, 'timebank_ledger_sequence')
            ? ['timebank_ledger_sequence' => self::sequence()] : [];
    }

    public static function sequence(): int
    {
        return self::installed() ? (int) TimebankCorrection::query()->max('id') : 0;
    }

    public static function assertMutable(string $type, int $id): void
    {
        abort_if(self::reversal($type, $id), 409, 'This customer charge is reversed; the original work must be retained.');
    }
}
