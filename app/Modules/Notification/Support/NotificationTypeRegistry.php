<?php

namespace App\Modules\Notification\Support;

use App\Modules\Notification\Notifications\AssetAlertTriggered;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Notifications\TicketAssigned;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Notification\Notifications\TicketSlaWarning;
use App\Modules\Notification\Notifications\TicketStatusChanged;
use App\Modules\Storage\Notifications\SupplierOrderImportDailyDigestNotification;
use App\Modules\Storage\Notifications\SupplierOrderImportExceptionNotification;
use InvalidArgumentException;

/**
 * Authoritative notification type, audience, preference, and channel policy.
 *
 * Every preference type must be declared here, including an explicit Web Push
 * eligibility decision. NotificationSetting remains only the persistence model.
 */
final class NotificationTypeRegistry
{
    public const AUDIENCE_INTERNAL = 'internal';

    public const AUDIENCE_CUSTOMER_PORTAL = 'customer_portal';

    public const WEB_PUSH_QUEUED_INTERNAL = 'queued_internal';

    public const WEB_PUSH_INBOUND_EMAIL_OUTBOX = 'inbound_email_outbox';

    public const WEB_PUSH_WORKDAY_RECEIPT = 'workday_receipt';

    public const GROUPS = [
        'workday' => [
            'label' => 'Workday',
            'description' => 'Personal reminders based on your work plan and absence.',
        ],
        'tickets' => [
            'label' => 'Tickets',
            'description' => 'Assignment, conversation, status, and SLA activity.',
        ],
        'email' => [
            'label' => 'Email',
            'description' => 'Inbound Email and customer replies routed through Nexum.',
        ],
        'assets' => [
            'label' => 'Assets and monitoring',
            'description' => 'Operational alerts from managed assets and monitoring sources.',
        ],
        'storage' => [
            'label' => 'Storage',
            'description' => 'Supplier-order import exceptions and operational summaries.',
        ],
        'system' => [
            'label' => 'System',
            'description' => 'Account and Nexum-wide system activity.',
        ],
        'customer_portal' => [
            'label' => 'Customer Portal',
            'description' => 'Customer-facing portal notifications managed in the portal.',
        ],
    ];

    /**
     * @var array<string, array{
     *     label:string,
     *     description:string,
     *     group:string,
     *     audience:string,
     *     channels:array{mail:bool,database:bool,web_push:bool,web_push_preview:bool,nextcloud_talk:bool},
     *     defaults:array{mail_enabled:bool,database_enabled:bool,web_push_enabled:bool,web_push_preview_enabled:bool,nextcloud_talk_enabled:bool},
     *     web_push:array{eligible:bool,delivery:?string,notification_class:?class-string,target_kind:?string,permission:?string,exclusion_reason:?string}
     * }>
     */
    private const DEFINITIONS = [
        'workday_reminder' => [
            'label' => 'Workday confirmation reminder',
            'description' => 'Review your actual time near the end of your planned workday. Turn all channels off to disable reminders. Snooze postpones the current reminder by 30 minutes.',
            'group' => 'workday',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => ['mail' => true, 'database' => true, 'web_push' => true, 'web_push_preview' => false, 'nextcloud_talk' => false],
            'defaults' => ['mail_enabled' => false, 'database_enabled' => true, 'web_push_enabled' => false, 'web_push_preview_enabled' => false, 'nextcloud_talk_enabled' => false],
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_WORKDAY_RECEIPT,
                'notification_class' => \App\Modules\Notification\Notifications\WorkdayReminderNotification::class,
                'target_kind' => 'workday_reminder',
                'permission' => 'workday.view_own',
                'exclusion_reason' => null,
            ],
        ],
        'ticket_created' => [
            'label' => 'Ticket Created',
            'description' => 'A new internal Ticket is created.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITHOUT_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_WEB_PUSH_EMITTER,
        ],
        'ticket_assigned' => [
            'label' => 'Ticket Assigned to You',
            'description' => 'A Ticket is assigned directly to you.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => TicketAssigned::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_TICKET,
                'permission' => 'ticket.view',
                'exclusion_reason' => null,
            ],
        ],
        'ticket_updated' => [
            'label' => 'Ticket Updated',
            'description' => 'General internal Ticket details are updated.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITHOUT_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_WEB_PUSH_EMITTER,
        ],
        'ticket_status_changed' => [
            'label' => 'Ticket Status Changed',
            'description' => 'The workflow status of one of your Tickets changes.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => TicketStatusChanged::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_TICKET,
                'permission' => 'ticket.view',
                'exclusion_reason' => null,
            ],
        ],
        'ticket_comment_added' => [
            'label' => 'Comment Added on Ticket',
            'description' => 'New conversation activity is added to one of your Tickets.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => TicketCommentAdded::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_TICKET,
                'permission' => 'ticket.view',
                'exclusion_reason' => null,
            ],
        ],
        'ticket_customer_reply_received' => [
            'label' => 'Customer reply on my Tickets',
            'description' => 'A customer replies to a Ticket assigned to you.',
            'group' => 'email',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INBOUND_EMAIL_CHANNELS,
            'defaults' => self::INBOUND_EMAIL_DEFAULTS,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_INBOUND_EMAIL_OUTBOX,
                'notification_class' => InboundEmailRoutedNotification::class,
                'target_kind' => 'inbound_email_ticket',
                'permission' => null,
                'exclusion_reason' => null,
            ],
        ],
        'inbound_email_received' => [
            'label' => 'New inbound Email',
            'description' => 'An authorized inbound Email is ready for inbox triage.',
            'group' => 'email',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INBOUND_EMAIL_CHANNELS,
            'defaults' => self::INBOUND_EMAIL_DEFAULTS,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_INBOUND_EMAIL_OUTBOX,
                'notification_class' => InboundEmailRoutedNotification::class,
                'target_kind' => 'inbound_email_message',
                'permission' => null,
                'exclusion_reason' => null,
            ],
        ],
        'ticket_sla_warning' => [
            'label' => 'SLA Warning',
            'description' => 'A Ticket response or resolution target needs attention.',
            'group' => 'tickets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => TicketSlaWarning::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_TICKET,
                'permission' => 'ticket.view',
                'exclusion_reason' => null,
            ],
        ],
        'asset_alert' => [
            'label' => 'Asset Alert',
            'description' => 'A managed asset reports an operational alert.',
            'group' => 'assets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => AssetAlertTriggered::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_ASSET,
                'permission' => 'asset.view',
                'exclusion_reason' => null,
            ],
        ],
        'asset_alert_resolved' => [
            'label' => 'Asset Alert Resolved',
            'description' => 'A managed asset alert returns to a resolved state.',
            'group' => 'assets',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITHOUT_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_WEB_PUSH_EMITTER,
        ],
        'invitation_sent' => [
            'label' => 'Invitation Sent',
            'description' => 'An account invitation is sent through the protected Email flow.',
            'group' => 'system',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITHOUT_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => false,
                'delivery' => null,
                'notification_class' => null,
                'target_kind' => null,
                'permission' => null,
                'exclusion_reason' => 'Account invitations remain Email-only because they may contain security-sensitive activation context.',
            ],
        ],
        'system_announcement' => [
            'label' => 'System Announcement',
            'description' => 'A Nexum-wide internal announcement is published.',
            'group' => 'system',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITHOUT_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_WEB_PUSH_EMITTER,
        ],
        'storage_purchase_import_exception' => [
            'label' => 'Supplier Order Import Exceptions',
            'description' => 'A supplier-order import needs operator attention.',
            'group' => 'storage',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => SupplierOrderImportExceptionNotification::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_STORAGE_IMPORTS,
                'permission' => 'storage.purchase_import_view',
                'exclusion_reason' => null,
            ],
        ],
        'storage_purchase_import_digest' => [
            'label' => 'Supplier Order Import Daily Digest',
            'description' => 'A daily supplier-order import activity summary is ready.',
            'group' => 'storage',
            'audience' => self::AUDIENCE_INTERNAL,
            'channels' => self::INTERNAL_CHANNELS_WITH_WEB_PUSH,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => [
                'eligible' => true,
                'delivery' => self::WEB_PUSH_QUEUED_INTERNAL,
                'notification_class' => SupplierOrderImportDailyDigestNotification::class,
                'target_kind' => InternalWebPushTargetAuthorizer::TARGET_STORAGE_IMPORTS,
                'permission' => 'storage.purchase_import_view',
                'exclusion_reason' => null,
            ],
        ],
        'portal_ticket_created' => [
            'label' => 'Ticket Created',
            'description' => 'A customer-facing Ticket is created or published.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_ticket_reply' => [
            'label' => 'Ticket Replies',
            'description' => 'A reply is added to a customer-facing Ticket.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_ticket_status_changed' => [
            'label' => 'Ticket Status Changes',
            'description' => 'A customer-facing Ticket changes status.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_document_published' => [
            'label' => 'New Documents',
            'description' => 'A new document is published to the customer portal.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_document_updated' => [
            'label' => 'Document Updates',
            'description' => 'A published customer portal document is updated.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_knowledge_published' => [
            'label' => 'New Knowledge Articles',
            'description' => 'A new Knowledge article is published to the customer portal.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_knowledge_updated' => [
            'label' => 'Knowledge Article Updates',
            'description' => 'A published customer portal Knowledge article is updated.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_quote_sent' => [
            'label' => 'New Quotes',
            'description' => 'A quote is published to the customer portal.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_quote_accepted' => [
            'label' => 'Quote Acceptance',
            'description' => 'A customer portal quote is accepted.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_contract_sent' => [
            'label' => 'New Contracts',
            'description' => 'A contract is published to the customer portal.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_contract_accepted' => [
            'label' => 'Contract Acceptance',
            'description' => 'A customer portal contract is accepted.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_order_published' => [
            'label' => 'New Orders',
            'description' => 'An order is published to the customer portal.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
        'portal_order_status_changed' => [
            'label' => 'Order Status Changes',
            'description' => 'A customer portal order changes status.',
            'group' => 'customer_portal',
            'audience' => self::AUDIENCE_CUSTOMER_PORTAL,
            'channels' => self::PORTAL_CHANNELS,
            'defaults' => self::DEFAULT_CHANNEL_STATES,
            'web_push' => self::NO_PORTAL_WEB_PUSH,
        ],
    ];

    private const DEFAULT_CHANNEL_STATES = [
        'mail_enabled' => true,
        'database_enabled' => true,
        'web_push_enabled' => false,
        'web_push_preview_enabled' => false,
        'nextcloud_talk_enabled' => false,
    ];

    private const INBOUND_EMAIL_DEFAULTS = [
        'mail_enabled' => false,
        'database_enabled' => true,
        'web_push_enabled' => false,
        'web_push_preview_enabled' => false,
        'nextcloud_talk_enabled' => false,
    ];

    private const INTERNAL_CHANNELS_WITH_WEB_PUSH = [
        'mail' => true,
        'database' => true,
        'web_push' => true,
        'web_push_preview' => false,
        'nextcloud_talk' => true,
    ];

    private const INTERNAL_CHANNELS_WITHOUT_WEB_PUSH = [
        'mail' => true,
        'database' => true,
        'web_push' => false,
        'web_push_preview' => false,
        'nextcloud_talk' => true,
    ];

    private const INBOUND_EMAIL_CHANNELS = [
        'mail' => true,
        'database' => true,
        'web_push' => true,
        'web_push_preview' => true,
        'nextcloud_talk' => true,
    ];

    private const PORTAL_CHANNELS = [
        'mail' => true,
        'database' => true,
        'web_push' => false,
        'web_push_preview' => false,
        'nextcloud_talk' => false,
    ];

    private const NO_WEB_PUSH_EMITTER = [
        'eligible' => false,
        'delivery' => null,
        'notification_class' => null,
        'target_kind' => null,
        'permission' => null,
        'exclusion_reason' => 'No complete internal emitter, safe payload, and target authorization contract currently exists.',
    ];

    private const NO_PORTAL_WEB_PUSH = [
        'eligible' => false,
        'delivery' => null,
        'notification_class' => null,
        'target_kind' => null,
        'permission' => null,
        'exclusion_reason' => 'Customer Portal Web Push is outside the approved internal-device boundary.',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string, mixed> */
    public static function definition(string $type): array
    {
        return self::DEFINITIONS[$type]
            ?? throw new InvalidArgumentException("Unknown notification type [{$type}].");
    }

    /** @return array<string, string> */
    public static function labels(?string $audience = null): array
    {
        return collect(self::definitionsForAudience($audience))
            ->mapWithKeys(fn (array $definition, string $type): array => [$type => $definition['label']])
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitionsForAudience(?string $audience): array
    {
        if ($audience === null) {
            return self::DEFINITIONS;
        }

        return array_filter(
            self::DEFINITIONS,
            fn (array $definition): bool => $definition['audience'] === $audience,
        );
    }

    /** @return array<string, array{label:string,description:string,types:array<string, array<string, mixed>>}> */
    public static function groupedInternal(): array
    {
        $groups = [];
        foreach (self::definitionsForAudience(self::AUDIENCE_INTERNAL) as $type => $definition) {
            $groupKey = $definition['group'];
            $groups[$groupKey] ??= [
                'label' => self::GROUPS[$groupKey]['label'],
                'description' => self::GROUPS[$groupKey]['description'],
                'types' => [],
            ];
            $groups[$groupKey]['types'][$type] = $definition;
        }

        return $groups;
    }

    /** @return array<string, bool> */
    public static function defaultsForType(string $type): array
    {
        return self::definition($type)['defaults'];
    }

    public static function supports(string $type, string $channel): bool
    {
        return (bool) (self::definition($type)['channels'][$channel] ?? false);
    }

    public static function supportsWebPush(string $type): bool
    {
        return self::supports($type, 'web_push');
    }

    public static function supportsWebPushPreview(string $type): bool
    {
        return self::supports($type, 'web_push_preview');
    }

    /** @return list<string> */
    public static function typesForNotificationClass(string $notificationClass): array
    {
        $types = [];
        foreach (self::DEFINITIONS as $type => $definition) {
            if ($definition['web_push']['notification_class'] === $notificationClass) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
