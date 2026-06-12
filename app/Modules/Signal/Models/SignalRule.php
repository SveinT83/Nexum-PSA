<?php

namespace App\Modules\Signal\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SignalRule extends Model
{
    protected $fillable = [
        'name',
        'description',
        'is_active',
        'priority',
        'conditions',
        'actions',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'conditions' => 'array',
        'actions' => 'array',
    ];

    public function executions(): HasMany
    {
        return $this->hasMany(SignalRuleExecution::class);
    }
}
