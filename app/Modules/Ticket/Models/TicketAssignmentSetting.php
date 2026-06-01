<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketAssignmentSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'is_assignable',
        'max_open_tickets',
        'assignment_preferences',
        'notes',
    ];

    protected $casts = [
        'is_assignable' => 'boolean',
        'max_open_tickets' => 'integer',
        'assignment_preferences' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ticket_assignment_setting_categories')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_assignment_setting_tags', 'ticket_assignment_setting_id', 'tag_id')
            ->withTimestamps();
    }
}
