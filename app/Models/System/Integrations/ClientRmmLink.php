<?php

namespace App\Models\System\Integrations;

use Illuminate\Database\Eloquent\Model;

class ClientRmmLink extends Model
{
    protected $fillable = [
        'integration_id',
        'external_id',
        'linkable_type',
        'linkable_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    /**
     * Get the owning linkable model.
     */
    public function linkable()
    {
        return $this->morphTo();
    }

    /**
     * Get the integration associated with the link.
     */
    public function integration()
    {
        return $this->belongsTo(Integration::class);
    }
}
