<?php

namespace App\Models\CS\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contracts extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'approval_status' => 'boolean',
            'approval_approved_at' => 'datetime',
            'start_date' => 'date',
            'allow_indexing_during_binding' => 'boolean',
            'allow_decrease_during_binding' => 'boolean',
            'last_indexed_at' => 'timestamp',
        ];
    }
}
