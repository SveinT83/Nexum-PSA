<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailHistoricalImportItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_IMPORTED = 'imported';

    public const STATUS_ALREADY_PRESENT = 'already_present';

    public const STATUS_SKIPPED_OUT_OF_SCOPE = 'skipped_out_of_scope';

    public const STATUS_SKIPPED_STALE = 'skipped_stale';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_historical_import_items';

    protected $fillable = [
        'email_historical_import_run_id',
        'email_folder_id',
        'uid_namespace_id',
        'folder_path',
        'uid_validity',
        'imap_uid',
        'status',
        'email_message_id',
        'email_mailbox_placement_id',
        'attempt_count',
        'first_attempt_at',
        'last_attempt_at',
        'completed_at',
        'error_code',
    ];

    protected $casts = [
        'email_historical_import_run_id' => 'integer',
        'email_folder_id' => 'integer',
        'uid_namespace_id' => 'integer',
        'uid_validity' => 'integer',
        'imap_uid' => 'integer',
        'email_message_id' => 'integer',
        'email_mailbox_placement_id' => 'integer',
        'attempt_count' => 'integer',
        'first_attempt_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailHistoricalImportRun::class, 'email_historical_import_run_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function uidNamespace(): BelongsTo
    {
        return $this->belongsTo(EmailFolderUidNamespace::class, 'uid_namespace_id');
    }
}
