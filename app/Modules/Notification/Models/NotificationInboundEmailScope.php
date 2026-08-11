<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationInboundEmailScope extends Model
{
    public const TYPE_INBOUND_EMAIL_RECEIVED = 'inbound_email_received';

    public const KIND_EMAIL_ACCOUNT = 'email_account';

    public const KIND_TICKET_QUEUE = 'ticket_queue';

    protected $table = 'notification_inbound_email_scopes';

    protected $fillable = [
        'user_id',
        'notification_type',
        'scope_kind',
        'scope_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
