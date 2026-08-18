<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class EmailConversationClassification extends Model
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_COMPATIBILITY_MIGRATION = 'compatibility_migration';

    public const SOURCE_RULE = 'rule';

    protected $table = 'email_conversation_classifications';

    protected $fillable = [
        'account_id',
        'email_conversation_id',
        'category_id',
        'assigned_by',
        'assigned_at',
        'source',
        'provenance',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'email_conversation_id' => 'integer',
        'category_id' => 'integer',
        'assigned_by' => 'integer',
        'assigned_at' => 'datetime',
        'provenance' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class, 'account_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(EmailConversation::class, 'email_conversation_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * Taxonomy owns tag definitions while Email owns their account-conversation assignment.
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable', 'taggables')
            ->withPivot('module')
            ->withTimestamps();
    }
}
