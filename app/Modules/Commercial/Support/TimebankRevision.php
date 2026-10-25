<?php

namespace App\Modules\Commercial\Support;

/** A balance precondition includes the actual period, usage, policy and authoritative Contract state. */
class TimebankRevision
{
    public static function of(array $balance, array $policy): string
    {
        return hash('sha256', json_encode(CommercialRevision::stable([
            'contract' => CommercialRevision::of($balance['contract']),
            'item' => $balance['contract_item']->id, 'start' => $balance['period_start']->toDateString(), 'end' => $balance['period_end']->toDateString(),
            'included' => $balance['included_minutes'], 'used' => $balance['used_minutes'], 'policy' => $policy,
        ]), JSON_THROW_ON_ERROR));
    }
}
