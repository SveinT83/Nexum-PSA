<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
use App\Modules\Notification\Support\NotificationTypeRegistry;
use Database\Factories\Notification\NotificationSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user notification channel preferences.
 *
 * Each row defines whether a specific notification type should be
 * delivered via a particular channel for a given user.
 */
class NotificationSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'notification_type',
        'mail_enabled',
        'database_enabled',
        'web_push_enabled',
        'web_push_preview_enabled',
        'nextcloud_talk_enabled',
        'nextcloud_talk_webhook_url',
    ];

    protected $casts = [
        'mail_enabled' => 'boolean',
        'database_enabled' => 'boolean',
        'web_push_enabled' => 'boolean',
        'web_push_preview_enabled' => 'boolean',
        'nextcloud_talk_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): NotificationSettingFactory
    {
        return NotificationSettingFactory::new();
    }

    /**
     * Get or create settings for a user + notification type.
     * Returns defaults if no explicit setting exists.
     */
    public static function getForUser(User $user, string $type): self
    {
        return static::firstOrCreate(
            ['user_id' => $user->id, 'notification_type' => $type],
            array_merge(self::defaultsForType($type), ['user_id' => $user->id, 'notification_type' => $type])
        );
    }

    /**
     * Get all settings for a user, creating defaults for any missing types.
     */
    public static function getAllForUser(User $user, ?array $types = null): \Illuminate\Support\Collection
    {
        $existing = static::where('user_id', $user->id)
            ->get()
            ->keyBy('notification_type');

        $settings = collect();
        foreach ($types ?? array_keys(NotificationTypeRegistry::all()) as $type) {
            if ($existing->has($type)) {
                $settings[$type] = $existing[$type];
            } else {
                $settings[$type] = (object) array_merge(
                    self::defaultsForType($type),
                    ['notification_type' => $type, 'user_id' => $user->id]
                );
            }
        }

        return $settings;
    }

    /**
     * @return array<string, bool>
     */
    public static function defaultsForType(string $type): array
    {
        return NotificationTypeRegistry::defaultsForType($type);
    }

    public static function supportsWebPush(string $type): bool
    {
        return NotificationTypeRegistry::supportsWebPush($type);
    }

    public static function supportsWebPushPreview(string $type): bool
    {
        return NotificationTypeRegistry::supportsWebPushPreview($type);
    }
}
