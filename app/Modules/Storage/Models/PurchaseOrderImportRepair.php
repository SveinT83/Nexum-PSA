<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderImportRepair extends Model
{
    public const STATUS_READY_FOR_REPROCESS = 'ready_for_reprocess';

    public const STATUS_PROPOSAL_ONLY_LOCKED_PURCHASE_ORDER = 'proposal_only_locked_purchase_order';

    public const STATUS_PROPOSAL_ONLY_STATE_CHANGED = 'proposal_only_state_changed';

    public const STATUS_APPLIED_PRE_HISTORY_PURCHASE_ORDER = 'applied_pre_history_purchase_order';

    public const UPDATED_AT = null;

    protected $table = 'storage_purchase_order_import_repairs';

    protected $fillable = [
        'import_id',
        'sequence',
        'ai_execution_uuid',
        'status',
        'original_document_checksum',
        'corrected_document',
        'corrected_document_checksum',
        'profile_candidate_version_id',
        'validation_results',
        'decision_summary',
        'actor_id',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'corrected_document' => 'array',
        'validation_results' => 'array',
        'decision_summary' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Supplier-order repair records are immutable.');
        });
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImport::class, 'import_id');
    }

    public function profileCandidateVersion(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfileVersion::class, 'profile_candidate_version_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
