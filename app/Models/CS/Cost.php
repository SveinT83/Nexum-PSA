<?php

namespace App\Models\CS;

use App\Models\Doc\Vendor;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class Cost extends Model
{
    use HasFactory;

    protected $table = 'costs';

    protected $fillable = [
        'name',
        'cost',
        'unit',
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
}
