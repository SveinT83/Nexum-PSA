<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderInventoryFolder extends Model
{
    public const STATUS_COMPLETE = 'complete';

    public const STATUS_INCOMPLETE = 'incomplete';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_provider_inventory_folders';

    public $timestamps = false;

    protected $fillable = [
        'email_provider_inventory_run_id',
        'account_id',
        'email_folder_id',
        'folder_path',
        'status',
        'reason_code',
        'expected_uid_validity',
        'observed_uid_validity',
        'start_uid_next',
        'end_uid_next',
        'start_exists_count',
        'end_exists_count',
        'scanned_message_count',
        'inventory_fingerprint',
        'started_at',
        'finished_at',
        'created_at',
    ];

    protected $casts = [
        'email_provider_inventory_run_id' => 'integer',
        'account_id' => 'integer',
        'email_folder_id' => 'integer',
        'expected_uid_validity' => 'integer',
        'observed_uid_validity' => 'integer',
        'start_uid_next' => 'integer',
        'end_uid_next' => 'integer',
        'start_exists_count' => 'integer',
        'end_exists_count' => 'integer',
        'scanned_message_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailProviderInventoryRun::class, 'email_provider_inventory_run_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(EmailProviderPlacementFinding::class, 'email_provider_inventory_folder_id');
    }
}
