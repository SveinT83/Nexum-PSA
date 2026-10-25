<?php

namespace App\Modules\Economy\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All Economy writers, including jobs and nested source callers, share one durable boundary. */
class EconomyMutex
{
    public function run(callable $apply, bool $advance = true): mixed
    {
        abort_unless(Schema::hasTable('economy_write_mutex'), 503, 'Apply the Economy command migration before enabling writes.');

        return DB::transaction(function () use ($apply, $advance) {
            DB::table('economy_write_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            $result = $apply();
            if ($advance) {
                DB::table('economy_write_mutex')->where('id', 1)->increment('revision');
            }

            return $result;
        }, 3);
    }

    public static function revision(): int
    {
        return Schema::hasTable('economy_write_mutex') ? (int) DB::table('economy_write_mutex')->where('id', 1)->value('revision') : 0;
    }
}
