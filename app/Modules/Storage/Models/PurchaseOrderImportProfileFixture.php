<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderImportProfileFixture extends Model
{
    protected $table = 'storage_purchase_order_import_profile_fixtures';

    protected $fillable = [
        'profile_id',
        'profile_version_id',
        'name',
        'fixture_type',
        'is_protected',
        'safe_source_snapshot',
        'expected_document',
        'source_checksum',
        'expected_checksum',
        'last_result',
        'last_result_details',
        'last_tested_at',
        'created_by',
    ];

    protected $casts = [
        'is_protected' => 'boolean',
        'safe_source_snapshot' => 'array',
        'expected_document' => 'array',
        'last_result_details' => 'array',
        'last_tested_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfile::class, 'profile_id');
    }

    public function profileVersion(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImportProfileVersion::class, 'profile_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
