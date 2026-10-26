<?php

namespace App\Modules\Risk\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Every browser/API Risk write takes this same lock before record locks. */
final class RiskMutex
{
    public function run(callable $callback): mixed
    {
        abort_unless(Schema::hasTable('risk_api_mutex'), 503, 'Apply the Risk command migration before writing Risk data.');

        return DB::transaction(function () use ($callback) {
            abort_unless(DB::table('risk_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Risk write lock is unavailable.');

            return $callback();
        }, 3);
    }
}
