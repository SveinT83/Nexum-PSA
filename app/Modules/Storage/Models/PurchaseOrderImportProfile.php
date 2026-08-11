<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrderImportProfile extends Model
{
    use SoftDeletes;

    public const STATE_DRAFT = 'draft';

    public const STATE_SHADOW = 'shadow';

    public const STATE_ACTIVE = 'active';

    public const STATE_DEGRADED = 'degraded';

    public const STATE_PAUSED = 'paused';

    public const STATE_RETIRED = 'retired';

    protected $table = 'storage_purchase_order_import_profiles';

    protected $fillable = [
        'vendor_id',
        'name',
        'slug',
        'description',
        'lifecycle_state',
        'priority',
        'active_version_id',
        'matching_scope',
        'policy_overrides',
        'health_state',
        'consecutive_failures',
        'last_matched_at',
        'last_success_at',
        'paused_at',
        'pause_reason',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'priority' => 'integer',
        'matching_scope' => 'array',
        'policy_overrides' => 'array',
        'consecutive_failures' => 'integer',
        'last_matched_at' => 'datetime',
        'last_success_at' => 'datetime',
        'paused_at' => 'datetime',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfileVersion::class, 'active_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportProfileVersion::class, 'profile_id')->orderByDesc('version_number');
    }

    public function fixtures(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportProfileFixture::class, 'profile_id');
    }

    public function metadataAudits(): HasMany
    {
        return $this->hasMany(PurchaseOrderImportProfileMetadataAudit::class, 'profile_id')
            ->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
