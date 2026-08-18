<?php

namespace App\Modules\Email\Models;

use App\Modules\Email\Models\Concerns\HasImmutableProviderBindingVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailProviderInventoryRun extends Model
{
    use HasImmutableProviderBindingVersion;

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_COMPLETED_WITH_AMBIGUITY = 'completed_with_ambiguity';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_FAILED = 'failed';

    protected $table = 'email_provider_inventory_runs';

    protected $fillable = [
        'account_id',
        'provider_binding_version',
        'provider',
        'status',
        'max_folders',
        'max_messages_per_folder',
        'folder_count',
        'complete_folder_count',
        'scanned_message_count',
        'confirmed_missing_count',
        'confirmed_move_count',
        'ambiguous_count',
        'inventory_scope_fingerprint',
        'failure_code',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'provider_binding_version' => 'integer',
        'max_folders' => 'integer',
        'max_messages_per_folder' => 'integer',
        'folder_count' => 'integer',
        'complete_folder_count' => 'integer',
        'scanned_message_count' => 'integer',
        'confirmed_missing_count' => 'integer',
        'confirmed_move_count' => 'integer',
        'ambiguous_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function folders(): HasMany
    {
        return $this->hasMany(EmailProviderInventoryFolder::class, 'email_provider_inventory_run_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(EmailProviderPlacementFinding::class, 'email_provider_inventory_run_id');
    }
}
