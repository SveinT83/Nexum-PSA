<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderAutomationPolicyRevision extends Model
{
    protected $table = 'storage_purchase_order_automation_policy_revisions';

    protected $fillable = [
        'policy_id',
        'revision_number',
        'snapshot',
        'checksum',
        'reason',
        'created_by',
        'activated_at',
    ];

    protected $casts = [
        'revision_number' => 'integer',
        'snapshot' => 'array',
        'activated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Purchase-order automation policy revisions are immutable.');
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderAutomationPolicy::class, 'policy_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
