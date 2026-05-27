<?php

namespace App\Models\Knowledge;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Article extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'slug',
        'body_markdown',
        'body_html',
        'visibility',
        'status',
        'owner_id',
        'category_id',
        'client_scope_id',
        'knowledge_shelf_id',
        'knowledge_book_id',
        'knowledge_chapter_id',
        'priority',
        'view_count',
        'next_review_at',
        'created_by',
        'updated_by',
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
        'next_review_at' => 'datetime',
        'priority' => 'integer',
        'view_count' => 'integer',
        'source_synced_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'source_payload' => 'array',
    ];

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function clientScope()
    {
        return $this->belongsTo(Client::class, 'client_scope_id');
    }

    public function knowledgeShelf()
    {
        return $this->belongsTo(Shelf::class, 'knowledge_shelf_id');
    }

    public function knowledgeBook()
    {
        return $this->belongsTo(Book::class, 'knowledge_book_id');
    }

    public function knowledgeChapter()
    {
        return $this->belongsTo(Chapter::class, 'knowledge_chapter_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($article) {
            if (empty($article->slug)) {
                $article->slug = Str::slug($article->title);
            }
        });
    }
}
