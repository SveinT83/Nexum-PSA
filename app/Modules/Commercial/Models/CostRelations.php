<?php

namespace App\Modules\Commercial\Models;

use Illuminate\Database\Eloquent\Model;
use App\Modules\Commercial\Models\Services\Services;

class CostRelations extends Model
{
    protected $table = 'cost_relations';

    protected $fillable = [
        'costId',
        'serviceId',
    ];

    protected $casts = [
        'costId' => 'string',
        'serviceId' => 'string',
    ];

    public function cost()
    {
        return $this->belongsTo(Cost::class, 'costId');
    }

    public function service()
    {
        return $this->belongsTo(Services::class, 'serviceId');
    }
}

