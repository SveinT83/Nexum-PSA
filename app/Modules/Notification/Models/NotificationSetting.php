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
        'portal_ticket_created' => 'Portal Ticket Created',
        'portal_ticket_reply' => 'Portal Ticket Reply',
        'portal_ticket_status_changed' => 'Portal Ticket Status Changed',
        'portal_document_published' => 'Portal Document Published',
        'portal_document_updated' => 'Portal Document Updated',
        'portal_knowledge_published' => 'Portal Knowledge Published',
        'portal_knowledge_updated' => 'Portal Knowledge Updated',
        'portal_quote_sent' => 'Portal Quote Sent',
        'portal_quote_accepted' => 'Portal Quote Accepted',
        'portal_contract_sent' => 'Portal Contract Sent',
        'portal_contract_accepted' => 'Portal Contract Accepted',
        'portal_order_published' => 'Portal Order Published',
        'portal_order_status_changed' => 'Portal Order Status Changed',
    ];

    public const CUSTOMER_PORTAL_TYPES = [
        'portal_ticket_created' => 'Ticket created',
        'portal_ticket_reply' => 'Ticket replies',
        'portal_ticket_status_changed' => 'Ticket status changes',
        'portal_document_published' => 'New documents',
        'portal_document_updated' => 'Document updates',
        'portal_knowledge_published' => 'New knowledge articles',
        'portal_knowledge_updated' => 'Knowledge article updates',
        'portal_quote_sent' => 'New quotes',
        'portal_quote_accepted' => 'Quote acceptance',
        'portal_contract_sent' => 'New contracts',
        'portal_contract_accepted' => 'Contract acceptance',
        'portal_order_published' => 'New orders',
        'portal_order_status_changed' => 'Order status changes',
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
