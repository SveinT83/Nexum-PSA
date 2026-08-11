<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PurchaseOrderImportAttempt extends Model
{
    protected $table = 'storage_purchase_order_import_attempts';

    protected $fillable = [
        'import_id',
        'attempt_number',
        'stage',
        'method',
        'status',
        'reason_code',
        'input_fingerprint',
        'output_fingerprint',
        'metadata',
        'service_identity',
        'actor_id',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Supplier-order import attempts are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Supplier-order import attempts are append-only.');
        });
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderImport::class, 'import_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
