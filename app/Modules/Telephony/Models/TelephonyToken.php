<?php

namespace App\Modules\Telephony\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelephonyToken extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'token_value',
        'last_used_at',
        'rotated_at',
    ];

    protected $casts = [
        'token_value' => 'encrypted',
        'last_used_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function intakeUrl(): string
    {
        return route('telephony.intake', ['token' => $this->token_value]);
    }
}
