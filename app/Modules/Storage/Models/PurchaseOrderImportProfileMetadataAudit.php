<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderImportProfileMetadataAudit extends Model
{
    protected $table = 'storage_purchase_order_import_profile_metadata_audits';

    protected $fillable = [
        'profile_id',
        'actor_id',
        'changed_fields',
        'before_snapshot',
        'after_snapshot',
        'reason',
    ];

    protected $casts = [
        'changed_fields' => 'array',
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Supplier-profile metadata audit records are immutable.');
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfile::class, 'profile_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
