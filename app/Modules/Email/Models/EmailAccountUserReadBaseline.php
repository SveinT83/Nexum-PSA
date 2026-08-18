<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailAccountUserReadBaseline extends Model
{
    protected $table = 'email_account_user_read_baselines';

    protected $fillable = [
        'email_account_id',
        'user_id',
        'access_epoch',
        'baseline_message_id',
        'ordinary_view_entitled',
        'source',
        'source_reference',
        'recorded_by',
        'recorded_at',
        'entitlement_changed_at',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'user_id' => 'integer',
        'access_epoch' => 'integer',
        'baseline_message_id' => 'integer',
        'ordinary_view_entitled' => 'boolean',
        'recorded_by' => 'integer',
        'recorded_at' => 'immutable_datetime',
        'entitlement_changed_at' => 'immutable_datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'email_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
