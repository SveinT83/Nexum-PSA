<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shelf extends Model
{
    protected $table = 'knowledge_shelves';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'source_system',
        'source_type',
        'source_id',
        'source_url',
        'source_checksum',
        'source_synced_at',
        'source_updated_at',
        'sync_status',
        'source_payload',
    ];

    protected $casts = [
        'source_synced_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'source_payload' => 'array',
    ];

    /**
     * Books grouped under this shelf in the Knowledge library.
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class, 'shelf_id')->orderBy('priority')->orderBy('name');
    }
}
