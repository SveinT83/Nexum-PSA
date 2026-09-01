<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAccountUserGrant extends Model
{
    protected $table = 'email_account_user_grants';

    protected $fillable = [
        'email_account_id',
        'user_id',
        'can_view',
        'can_organize',
        'can_send',
        'granted_by',
        'granted_at',
    ];

    // Written only by EmailLiveAuthorityCoordinator.
    protected $guarded = ['email_live_enable_generation'];

    protected $casts = [
        'can_view' => 'boolean',
        'can_organize' => 'boolean',
        'can_send' => 'boolean',
        'granted_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
