<?php

namespace App\Modules\Email\Models;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailFolderNavigationPreference extends Model
{
    protected $table = 'email_folder_navigation_preferences';

    protected $fillable = [
        'user_id',
        'email_folder_id',
        'is_expanded',
    ];

    protected $casts = [
        'is_expanded' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(EmailFolder::class, 'email_folder_id');
    }
}
