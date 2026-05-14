<?php

namespace App\Modules\Ticket\Models;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketTechnicianProfile extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'is_assignable',
        'max_open_tickets',
        'timezone',
        'working_hours',
        'assignment_preferences',
        'notes',
    ];

    protected $casts = [
        'is_assignable' => 'boolean',
        'max_open_tickets' => 'integer',
        'working_hours' => 'array',
        'assignment_preferences' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'ticket_technician_profile_categories')
            ->withTimestamps();
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'ticket_technician_profile_tags', 'ticket_technician_profile_id', 'tag_id')
            ->withTimestamps();
    }
}
