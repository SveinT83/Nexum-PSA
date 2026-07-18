<?php

namespace App\Modules\Ticket\Support;

final class TicketWorkflowCustomerNotificationPolicy
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_PORTAL = 'portal';

    public const DEFAULT_TEMPLATE_KEY = 'ticket_status_update';

    /** @return array<string, string> */
    public static function channelDefinitions(): array
    {
        return [
            self::CHANNEL_EMAIL => 'Email to Ticket contact',
            self::CHANNEL_PORTAL => 'Customer portal notification',
        ];
    }

    /**
     * Keep the published workflow contract stable and make old definitions
     * explicitly silent when they do not contain a customer-update policy.
     *
     * @param  array<string, mixed>|null  $policy
     * @return array{enabled: bool, channels: array<int, string>, email_template_key: string, message: string|null}
     */
    public static function normalize(?array $policy): array
    {
        $supported = array_keys(self::channelDefinitions());
        $channels = collect($policy['channels'] ?? [])
            ->filter(fn ($channel) => is_string($channel) && in_array($channel, $supported, true))
            ->unique()
            ->values()
            ->all();
        $message = trim((string) ($policy['message'] ?? ''));

        return [
            'enabled' => (bool) ($policy['enabled'] ?? false),
            'channels' => $channels,
            'email_template_key' => trim((string) ($policy['email_template_key'] ?? self::DEFAULT_TEMPLATE_KEY)) ?: self::DEFAULT_TEMPLATE_KEY,
            'message' => $message !== '' ? $message : null,
        ];
    }

    /** @param array<string, mixed>|null $policy */
    public static function isEnabled(?array $policy): bool
    {
        return self::normalize($policy)['enabled'];
    }
}
