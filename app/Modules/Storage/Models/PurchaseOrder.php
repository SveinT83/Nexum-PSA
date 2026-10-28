<?php

namespace App\Modules\Storage\Models;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    private const PENDING_NUMBER_PREFIX = 'pending-db-id-';

    private bool $assigningGeneratedNumber = false;

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $order): void {
            if ($order->isDirty('po_number') && ! $order->assigningGeneratedNumber) {
                throw ValidationException::withMessages(['po_number' => 'The generated Nexum order number cannot be changed.']);
            }
        });
    }

    public static function pendingNumber(): string
    {
        return self::PENDING_NUMBER_PREFIX.(string) Str::uuid();
    }

    public static function isPendingNumber(mixed $number): bool
    {
        return is_string($number) && str_starts_with($number, self::PENDING_NUMBER_PREFIX)
            && Str::isUuid(substr($number, strlen(self::PENDING_NUMBER_PREFIX)));
    }

    /** @internal Only the shared allocator assigns a freshly created pending record inside its transaction. */
    public function assignGeneratedNumber(string $number): void
    {
        if (! $this->exists || ! $this->wasRecentlyCreated || $this->getConnection()->transactionLevel() < 1
            || ! self::isPendingNumber($this->getRawOriginal('po_number'))
            || ! preg_match('/\A[1-9][0-9]{0,18}\z/D', $number)) {
            throw ValidationException::withMessages(['po_number' => 'Only original creation may assign a Nexum order number.']);
        }
        $this->assigningGeneratedNumber = true;
        try {
            $this->forceFill(['po_number' => $number])->save();
        } finally {
            $this->assigningGeneratedNumber = false;
        }
    }

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'storage_purchase_orders';

    protected $fillable = [
        'po_number',
        'vendor_id',
        'supplier_name_snapshot',
        'deliver_to_warehouse_id',
        'status',
        'status_changed_at',
        'status_changed_by',
        'closed_at',
        'cancelled_at',
        'vendor_ref',
        'tracking_no',
        'ordered_at',
        'expected_at',
        'currency',
        'notes',
        'metadata',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'ordered_at' => 'date',
        'expected_at' => 'date',
        'status_changed_at' => 'datetime',
        'closed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function deliverToWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'deliver_to_warehouse_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(PurchaseShipment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PurchaseReceipt::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusChanger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    public function supplierOrderImport(): HasOne
    {
        return $this->hasOne(PurchaseOrderImport::class, 'purchase_order_id');
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_DRAFT,
            self::STATUS_ORDERED,
            self::STATUS_PARTIALLY_RECEIVED,
            self::STATUS_RECEIVED,
            self::STATUS_CLOSED,
            self::STATUS_CANCELLED,
        ];
    }
}
