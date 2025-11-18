<?php

namespace App\Domain\Email\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailAccount extends Model
{
    protected $table = 'email_accounts';

    protected $fillable = [
        'address', 'description', 'from_name',
        'is_active', 'is_global_default', 'defaults_for',
        // IMAP
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_secret', 'imap_auth_type',
        // SMTP
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_secret', 'smtp_auth_type',
        // Health
        'last_test_at', 'last_test_result', 'last_error_code', 'last_error_message',
        'last_successful_fetch_at', 'last_successful_send_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_global_default' => 'boolean',
        'defaults_for' => 'array',
        'last_test_at' => 'datetime',
        'last_successful_fetch_at' => 'datetime',
        'last_successful_send_at' => 'datetime',
    ];

    protected $hidden = [
        'imap_secret', 'smtp_secret',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'account_id');
    }
}
