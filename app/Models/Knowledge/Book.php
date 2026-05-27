<?php

namespace App\Models\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    protected $table = 'knowledge_books';

    protected $fillable = [
        'shelf_id',
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
     * Shelf that groups this book.
     */
    public function shelf(): BelongsTo
    {
        return $this->belongsTo(Shelf::class, 'shelf_id');
    }

    /**
     * Optional chapter groups inside this book.
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class, 'book_id')->orderBy('priority')->orderBy('name');
    }

    /**
     * Pages that sit directly under the book without a chapter.
     */
    public function pages(): HasMany
    {
        return $this->hasMany(Article::class, 'knowledge_book_id')
            ->whereNull('knowledge_chapter_id')
            ->orderBy('priority')
            ->orderBy('title');
    }
}
