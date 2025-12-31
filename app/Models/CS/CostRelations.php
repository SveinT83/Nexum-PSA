<?php

namespace App\Models\CS;

use Illuminate\Database\Eloquent\Model;
use App\Models\CS\Services\Services;

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

