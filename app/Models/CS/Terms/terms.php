<?php

namespace App\Models\CS\Terms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\CS\Services\Services;

class terms extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'term',
        'legal',
    ];

    public function services()
    {
        return $this->belongsToMany(
            Services::class,
            'service_term_pivot',
            'term_id',
            'service_id'
        )->withTimestamps();
    }
}
