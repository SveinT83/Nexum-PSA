<?php

namespace App\Modules\Email\Models;

use Illuminate\Database\Eloquent\Model;

class EmailLiveGlobalAuthorityState extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'email_live_global_authority_states';

    protected $fillable = [
        'active_user_generation',
        'content_audience_generation',
        'content_ability_generation',
        'authorization_generation',
    ];

    protected $casts = [
        'active_user_generation' => 'integer',
        'content_audience_generation' => 'integer',
        'content_ability_generation' => 'integer',
        'authorization_generation' => 'integer',
    ];
}
