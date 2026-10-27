<?php

namespace App\Modules\LeadIntelligence\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable command claim and encrypted result; raw request/exception never stored. */
class LeadApiCommand extends Model
{
    protected $table = 'lead_intelligence_api_commands';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['result' => 'encrypted:array'];
}
