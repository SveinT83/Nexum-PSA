<?php

namespace App\Modules\Commercial\Models\Terms;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Packages\Package;

class terms extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'type',
        'content',
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

    public function packages()
    {
        return $this->belongsToMany(
            Package::class,
            'package_term_pivot',
            'term_id',
            'package_id'
        )->withTimestamps();
    }

    public function isInUse(): bool
    {
        return $this->services()->exists()
            || $this->packages()->exists();
    }
}
