<?php

namespace App\Models\CS;

use Illuminate\Database\Eloquent\Model;

class costRelations extends Model
{
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
        return $this->belongsTo(cost::class, 'costId');
    }

    public function service()
    {
        return $this->belongsTo(service::class, 'serviceId');
    }
}
