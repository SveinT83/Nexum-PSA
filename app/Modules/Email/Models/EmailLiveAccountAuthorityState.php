<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLiveAccountAuthorityState extends Model
{
    protected $table = 'email_live_account_authority_states';

    protected $fillable = [
        'email_account_id',
        'audience_generation',
        'owner_user_id',
        'owner_enable_generation',
    ];

    protected $casts = [
        'email_account_id' => 'integer',
        'audience_generation' => 'integer',
        'owner_user_id' => 'integer',
        'owner_enable_generation' => 'integer',
    ];
}
