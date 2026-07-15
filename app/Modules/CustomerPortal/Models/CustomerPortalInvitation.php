<?php

namespace App\Modules\CustomerPortal\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPortalInvitation extends Model
{
    protected $fillable = [
        'contact_id',
        'client_id',
        'site_id',
        'user_id',
        'email',
        'role',
        'token_hash',
        'expires_at',
        'accepted_at',
        'revoked_at',
        'created_by',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        return self::query()
            ->with(['contact.emails', 'client', 'site', 'user'])
            ->where('token_hash', self::hashToken($token))
            ->first();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isValid(): bool
    {
        return $this->accepted_at === null
            && $this->revoked_at === null
            && $this->expires_at?->isFuture();
    }

    public function roleLabel(): string
    {
        return CustomerPortalMembership::roleOptions()[$this->role] ?? ucfirst(str_replace('_', ' ', $this->role));
    }
}
