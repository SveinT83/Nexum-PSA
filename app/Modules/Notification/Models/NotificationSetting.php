<?php

namespace App\Modules\Notification\Models;

use App\Models\Core\User;
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
        'nextcloud_talk_enabled',
        'nextcloud_talk_webhook_url',
    ];

    protected $casts = [
        'mail_enabled' => 'boolean',
        'database_enabled' => 'boolean',
        'nextcloud_talk_enabled' => 'boolean',
    ];

    /**
     * All notification types available in the system.
     */
    public const TYPES = [
        'ticket_created' => 'Ticket Created',
        'ticket_assigned' => 'Ticket Assigned to You',
        'ticket_updated' => 'Ticket Updated',
        'ticket_status_changed' => 'Ticket Status Changed',
        'ticket_comment_added' => 'Comment Added on Ticket',
        'ticket_sla_warning' => 'SLA Warning',
        'asset_alert' => 'Asset Alert',
        'asset_alert_resolved' => 'Asset Alert Resolved',
        'invitation_sent' => 'Invitation Sent',
        'system_announcement' => 'System Announcement',
    ];

    /**
     * Default channel states for new notification types.
     */
    public const DEFAULTS = [
        'mail_enabled' => true,
        'database_enabled' => true,
        'nextcloud_talk_enabled' => false,
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
            array_merge(self::DEFAULTS, ['user_id' => $user->id, 'notification_type' => $type])
        );
    }

    /**
     * Get all settings for a user, creating defaults for any missing types.
     */
    public static function getAllForUser(User $user): \Illuminate\Support\Collection
    {
        $existing = static::where('user_id', $user->id)
            ->get()
            ->keyBy('notification_type');

        $settings = collect();
        foreach (self::TYPES as $type => $label) {
            if ($existing->has($type)) {
                $settings[$type] = $existing[$type];
            } else {
                $settings[$type] = (object) array_merge(
                    self::DEFAULTS,
                    ['notification_type' => $type, 'user_id' => $user->id]
                );
            }
        }

        return $settings;
    }
}
