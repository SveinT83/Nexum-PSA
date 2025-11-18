<?php

namespace App\Domain\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailHealthCheck extends Model
{
    protected $table = 'email_health_checks';
    public $timestamps = false;

    protected $fillable = [
        'account_id', 'checked_at', 'imap_status', 'smtp_status', 'error_code', 'error_message', 'durations_json',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'durations_json' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }
}
