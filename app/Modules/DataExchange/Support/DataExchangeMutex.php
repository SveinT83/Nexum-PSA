<?php

namespace App\Modules\DataExchange\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One native row serializes related configuration, replay and import/scheduler decisions. */
final class DataExchangeMutex
{
    public function run(callable $apply): mixed
    {
        abort_unless(Schema::hasTable('data_exchange_write_mutex'), 503, 'Apply the Data Exchange command migration before enabling writes.');

        return DB::transaction(function () use ($apply) {
            DB::table('data_exchange_write_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();

            return $apply();
        });
    }
}
