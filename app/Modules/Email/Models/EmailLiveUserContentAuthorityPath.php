<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class EmailLiveUserContentAuthorityPath extends Model
{
    public const TYPE_DIRECT_PERMISSION = 'direct_permission';

    public const TYPE_ROLE_MEMBERSHIP = 'role_membership';

    protected $table = 'email_live_user_content_authority_paths';

    protected $fillable = [
        'user_id',
        'path_type',
        'permission_id',
        'role_id',
        'direct_slot',
        'enabled',
        'enable_generation',
        'enabled_at',
        'disabled_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'permission_id' => 'integer',
        'role_id' => 'integer',
        'direct_slot' => 'integer',
        'enabled' => 'boolean',
        'enable_generation' => 'integer',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
