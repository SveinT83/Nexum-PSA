<?php

namespace App\Models\Knowledge;

use App\Models\System\Category;
use App\Models\Core\User;
use App\Models\Clients\Client;
use App\Models\System\Tag;
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
        'view_count',
        'next_review_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'next_review_at' => 'datetime',
        'view_count' => 'integer',
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
