<?php

namespace App\Modules\Ticket\Models;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Task\Models\Task;
use Database\Factories\Ticket\TicketFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'ticket_key',
        'type',
        'ticket_type_id',
        'queue_id',
        'status_id',
        'priority_id',
        'sla_id',
        'sla_source',
        'sla_source_id',
        'sla_snapshot',
        'workflow_id',
        'category_id',
        'client_id',
        'site_id',
        'contact_id',
        'asset_id',
        'owner_id',
        'created_by',
        'updated_by',
        'channel',
        'subject',
        'description',
        'impact',
        'urgency',
        'is_unread',
        'first_response_due_at',
        'resolve_due_at',
        'first_responded_at',
        'resolved_at',
        'closed_at',
        'merged_into_ticket_id',
        'merged_by',
        'merged_at',
        'metadata',
    ];

    protected $casts = [
        'is_unread' => 'boolean',
        'metadata' => 'array',
        'sla_snapshot' => 'array',
        'first_response_due_at' => 'datetime',
        'resolve_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'merged_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'ticket_key';
    }

    protected static function newFactory(): TicketFactory
    {
        return TicketFactory::new();
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(TicketQueue::class, 'queue_id');
    }

    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class, 'ticket_type_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'status_id');
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(TicketPriority::class, 'priority_id');
    }

    public function sla(): BelongsTo
    {
        return $this->belongsTo(Sla::class, 'sla_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(TicketWorkflow::class, 'workflow_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(ClientUser::class, 'contact_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(ClientSite::class, 'site_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'merged_into_ticket_id')->withTrashed();
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'asset_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TicketTimeEntry::class);
    }

    public function costEntries(): HasMany
    {
        return $this->hasMany(TicketCostEntry::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }

    public function tasks(): MorphMany
    {
        return $this->morphMany(Task::class, 'owner');
    }
}
