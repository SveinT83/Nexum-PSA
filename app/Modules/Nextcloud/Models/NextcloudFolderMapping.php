<?php

namespace App\Modules\Nextcloud\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class NextcloudFolderMapping extends Model
{
    protected $fillable = [
        'connection_id',
        'mappable_type',
        'mappable_id',
        'purpose',
        'remote_path',
        'remote_file_id',
        'auto_created',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'auto_created' => 'boolean',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NextcloudConnection::class, 'connection_id');
    }

    public function mappable(): MorphTo
    {
        return $this->morphTo();
    }
}
