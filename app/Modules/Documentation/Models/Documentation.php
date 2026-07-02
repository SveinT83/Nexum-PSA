<?php

namespace App\Modules\Documentation\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\WorkContext\Models\WorkContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Documentation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'template_id',
        'category_id',
        'client_id',
        'work_context_id',
        'site_id',
        'title',
        'scope_type',
        'template_snapshot_json',
        'data_json',
    ];

    protected $casts = [
        'template_snapshot_json' => 'array',
        'data_json' => 'array',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentationTemplate::class, 'template_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function workContext(): BelongsTo
    {
        return $this->belongsTo(WorkContext::class, 'work_context_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }
}
