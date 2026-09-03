<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRuleDraft extends Model
{
    protected $fillable = [
        'email_rule_id',
        'base_email_rule_version_id',
        'lock_version',
        'payload_json',
        'checksum',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'lock_version' => 'integer',
        'payload_json' => 'array',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(EmailRule::class, 'email_rule_id');
    }

    public function baseVersion(): BelongsTo
    {
        return $this->belongsTo(EmailRuleVersion::class, 'base_email_rule_version_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
