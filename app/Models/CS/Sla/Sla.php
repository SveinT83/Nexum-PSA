<?php

namespace App\Models\CS\Sla;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sla extends Model
{
    use SoftDeletes;

    protected $table = 'sla';

    protected $fillable = [
        'name',
        'description',
        'low_firstResponse',
        'low_firstResponse_type',
        'low_onsite',
        'low_onsite_type',
        'medium_firstResponse',
        'medium_firstResponse_type',
        'medium_onsite',
        'medium_onsite_type',
        'high_firstResponse',
        'high_firstResponse_type',
        'high_onsite',
        'high_onsite_type',
        'created_by_user_id',
    ];
}
