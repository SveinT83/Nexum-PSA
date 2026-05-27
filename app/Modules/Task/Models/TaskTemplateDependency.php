<?php

namespace App\Modules\Task\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskTemplateDependency extends Model
{
    protected $fillable = [
        'template_item_id',
        'depends_on_template_item_id',
        'dependency_type',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'template_item_id');
    }

    public function dependsOnTemplateItem(): BelongsTo
    {
        return $this->belongsTo(TaskTemplateItem::class, 'depends_on_template_item_id');
    }
}
