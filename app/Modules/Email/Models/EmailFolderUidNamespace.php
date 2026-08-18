<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailFolderUidNamespace extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_LEGACY_UNKNOWN = 'legacy_unknown';

    protected $table = 'email_folder_uid_namespaces';

    protected $fillable = [
        'account_id',
        'email_folder_id',
        'generation',
        'uid_validity',
        'uid_next_at_establishment',
        'live_start_uid',
        'status',
        'provenance_code',
        'established_by',
        'established_at',
        'superseded_at',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'email_folder_id' => 'integer',
        'generation' => 'integer',
        'uid_validity' => 'integer',
        'uid_next_at_establishment' => 'integer',
        'live_start_uid' => 'integer',
        'established_by' => 'integer',
        'established_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function establishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'established_by');
    }

    public function placements(): HasMany
    {
        return $this->hasMany(EmailMailboxPlacement::class, 'uid_namespace_id');
    }
}
