<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role;

class AiAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_provider_id',
        'name',
        'slug',
        'model',
        'instructions',
        'data_sources',
        'allowed_tools',
        'allowed_api_scopes',
        'can_execute_actions',
        'is_default',
        'default_domains',
        'is_active',
    ];

    protected $casts = [
        'data_sources' => 'array',
        'allowed_tools' => 'array',
        'allowed_api_scopes' => 'array',
        'can_execute_actions' => 'boolean',
        'is_default' => 'boolean',
        'default_domains' => 'array',
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'ai_agent_role')
            ->withTimestamps();
    }
}
