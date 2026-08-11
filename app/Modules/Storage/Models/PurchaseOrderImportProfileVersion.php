<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderImportProfileVersion extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_REJECTED = 'rejected';

    private const IMMUTABLE_FIELDS = [
        'profile_id',
        'version_number',
        'parent_version_id',
        'schema_version',
        'definition',
        'checksum',
        'source',
        'created_by',
    ];

    protected $table = 'storage_purchase_order_import_profile_versions';

    protected $fillable = [
        'profile_id',
        'version_number',
        'parent_version_id',
        'schema_version',
        'status',
        'definition',
        'checksum',
        'source',
        'test_metrics',
        'created_by',
        'activated_by',
        'validated_at',
        'activated_at',
        'activation_reason',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'definition' => 'array',
        'test_metrics' => 'array',
        'validated_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            foreach (self::IMMUTABLE_FIELDS as $field) {
                if ($version->isDirty($field)) {
                    throw new LogicException("Profile version field {$field} is immutable.");
                }
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfile::class, 'profile_id');
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
