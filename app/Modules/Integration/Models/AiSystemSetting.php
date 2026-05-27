<?php

namespace App\Modules\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class AiSystemSetting extends Model
{
    protected $fillable = [
        'context_message_limit',
        'chat_retention_days',
        'delete_empty_chats_after_days',
        'delete_failed_pending_after_hours',
        'cleanup_enabled',
        'last_cleanup_at',
        'last_cleanup_summary',
    ];

    protected $casts = [
        'context_message_limit' => 'integer',
        'chat_retention_days' => 'integer',
        'delete_empty_chats_after_days' => 'integer',
        'delete_failed_pending_after_hours' => 'integer',
        'cleanup_enabled' => 'boolean',
        'last_cleanup_at' => 'datetime',
        'last_cleanup_summary' => 'array',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([], static::defaults());
    }

    public static function defaults(): array
    {
        return [
            'context_message_limit' => 20,
            'chat_retention_days' => 90,
            'delete_empty_chats_after_days' => 7,
            'delete_failed_pending_after_hours' => 24,
            'cleanup_enabled' => true,
        ];
    }
}
