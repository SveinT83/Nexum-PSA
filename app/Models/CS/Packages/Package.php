<?php

namespace App\Models\CS\Packages;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\CS\Services\Services;

class Package extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'status',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    public function services()
    {
        return $this->belongsToMany(Services::class, 'package_service', 'package_id', 'service_id');
    }
}
