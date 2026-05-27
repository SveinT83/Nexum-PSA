<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTemplateChecklistItem extends Model
{
    protected $fillable = [
        'template_item_id',
        'title',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'template_item_id');
    }
}
