<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EmailProviderPlacementFinding extends Model
{
    public const TYPE_CONFIRMED_MISSING = 'confirmed_missing';

    public const TYPE_CONFIRMED_MOVE = 'confirmed_move';

    public const TYPE_AMBIGUOUS = 'ambiguous';

    public const TYPE_REAPPEARED = 'reappeared';

    protected $table = 'email_provider_placement_findings';

    public $timestamps = false;

    protected $fillable = [
        'email_provider_inventory_run_id',
        'email_provider_inventory_folder_id',
        'account_id',
        'source_placement_id',
        'email_message_id',
        'email_conversation_id',
        'source_folder_id',
        'source_folder_path',
        'source_uid_validity',
        'source_uid',
        'finding_type',
        'reason_code',
        'identity_fingerprint',
        'target_placement_id',
        'target_folder_id',
        'target_folder_path',
        'target_uid_validity',
        'target_uid',
        'cleanup_due_at',
        'observed_at',
        'created_at',
    ];

    protected $casts = [
        'email_provider_inventory_run_id' => 'integer',
        'email_provider_inventory_folder_id' => 'integer',
        'account_id' => 'integer',
        'source_placement_id' => 'integer',
        'email_message_id' => 'integer',
        'email_conversation_id' => 'integer',
        'source_folder_id' => 'integer',
        'source_uid_validity' => 'integer',
        'source_uid' => 'integer',
        'target_placement_id' => 'integer',
        'target_folder_id' => 'integer',
        'target_uid_validity' => 'integer',
        'target_uid' => 'integer',
        'cleanup_due_at' => 'datetime',
        'observed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Provider placement findings are immutable audit evidence.');
        });

        static::deleting(function (): void {
            throw new LogicException('Provider placement findings are immutable audit evidence.');
        });
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(EmailProviderInventoryRun::class, 'email_provider_inventory_run_id');
    }

    public function folderAudit(): BelongsTo
    {
        return $this->belongsTo(EmailProviderInventoryFolder::class, 'email_provider_inventory_folder_id');
    }
}
