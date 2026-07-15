<?php

namespace App\Modules\CustomerPortal\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPortalMembership extends Model
{
    public const ROLE_CUSTOMER_ADMIN = 'customer_admin';
    public const ROLE_SITE_ADMIN = 'site_admin';
    public const ROLE_VIEWER = 'viewer';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'customer_portal_account_id',
        'client_id',
        'site_id',
        'role',
        'status',
        'capabilities',
        'created_by',
        'disabled_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'disabled_at' => 'datetime',
    ];

    public static function roleOptions(): array
    {
        return [
            self::ROLE_CUSTOMER_ADMIN => 'Customer admin',
            self::ROLE_SITE_ADMIN => 'Site admin',
            self::ROLE_VIEWER => 'Viewer',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalAccount::class, 'customer_portal_account_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function roleLabel(): string
    {
        return self::roleOptions()[$this->role] ?? ucfirst(str_replace('_', ' ', $this->role));
    }
}
