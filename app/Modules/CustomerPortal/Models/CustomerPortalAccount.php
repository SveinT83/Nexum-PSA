<?php

namespace App\Modules\CustomerPortal\Models;

use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPortalAccount extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'user_id',
        'contact_id',
        'status',
        'last_login_at',
        'accepted_terms_at',
        'metadata',
    ];

    protected $casts = [
        'last_login_at' => 'datetime',
        'accepted_terms_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CustomerPortalMembership::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
