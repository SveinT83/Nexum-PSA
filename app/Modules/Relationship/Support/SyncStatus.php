<?php

namespace App\Modules\Relationship\Support;

class SyncStatus
{
    public const PENDING = 'pending';

    public const SYNCED = 'synced';

    public const FAILED = 'failed';

    public const CONFLICT = 'conflict';

    public const SKIPPED = 'skipped';
}
