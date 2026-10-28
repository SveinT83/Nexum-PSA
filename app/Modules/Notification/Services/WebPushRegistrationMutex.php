<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A supported managed installation has one authoritative subscription/audit transaction boundary. */
final class WebPushRegistrationMutex
{
    public const TABLE = 'notification_web_push_setup_mutex';

    public const MIGRATION = '2026_10_28_030000_create_notification_web_push_setup_mutex';

    public function installed(): bool
    {
        try {
            return Schema::hasTable(self::TABLE) && DB::table(config('database.migrations.table', 'migrations'))->where('migration', self::MIGRATION)->exists()
                && DB::table(self::TABLE)->where('id', 1)->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function sameDatabase(): bool
    {
        return ((new WebPushSubscription)->getConnectionName() ?: config('database.default')) === config('database.default');
    }

    public function run(\Closure $action): mixed
    {
        abort_unless($this->installed() && $this->sameDatabase(), 409, 'Managed Web Push requires the installed registration mutex and one subscription/audit database.');

        return DB::transaction(function () use ($action) {
            abort_unless(DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first(), 503, 'Web Push registration synchronization is unavailable.');

            return $action();
        });
    }
}
