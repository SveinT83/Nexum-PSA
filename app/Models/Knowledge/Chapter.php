<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chapter extends Model
{
    protected $table = 'knowledge_chapters';

    protected $fillable = [
        'book_id',
        'name',
        'slug',
        'description',
        'priority',
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
        'priority' => 'integer',
        'source_synced_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'source_payload' => 'array',
    ];

    /**
     * Book that owns this chapter.
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class, 'book_id');
    }

    /**
     * Pages contained in this chapter.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Article::class, 'knowledge_chapter_id')
            ->orderBy('priority')
            ->orderBy('title');
    }
}
