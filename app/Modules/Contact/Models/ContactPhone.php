<?php

namespace App\Modules\Contact\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactPhone extends Model
{
    protected $fillable = [
        'contact_id',
        'label',
        'phone',
        'is_primary',
        'sms_allowed',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'sms_allowed' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
