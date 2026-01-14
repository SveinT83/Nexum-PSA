<?php

namespace App\Models\CS;

use Illuminate\Database\Eloquent\Factories\HasFactory;
Use App\Models\User;
Use App\Models\Doc\Vendor;
Use App\Models\Economy\Units;
use Illuminate\Database\Eloquent\Model;


class Cost extends Model
{

    protected $table = 'costs';

    protected $fillable = [
        'name',
        'cost',
        'unitId', //The ID of the unit table
        'recurrence',
        'vendor_id',
        'note',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function unit()
    {
        return $this->belongsTo(Units::class, 'unitId');
    }

}
