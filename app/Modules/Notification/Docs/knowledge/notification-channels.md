Notification channels define how Nexum delivers system notifications outside the in-app notification bell.

## Nextcloud Talk

Nextcloud Talk notifications support two delivery modes:

- Talk Bot API through the active Nextcloud connection.
- Legacy incoming webhook URL through Notification channel settings.

The Talk Bot API is preferred when the selected Nextcloud connection has `talk_bot_id` and
`talk_bot_secret` configured. Webhook delivery remains available for installations that do not yet
use the bot API.

The Notification module owns channel enablement, fallback webhook URL, and per-user notification
preferences. The Nextcloud module owns the Nextcloud integration itself, including base URL,
credentials, sync settings, users, groups, calendars, folder mappings, Talk bot id, Talk bot shared
secret, default conversation token, and Talk bot features.

Before the Nextcloud Talk channel can be enabled, at least one active Nextcloud integration must exist under Admin > Nextcloud. If no active integration exists, the channel remains disabled even if a webhook URL is saved.

## Configuration

Use Admin > Notification channels > Nextcloud Talk to choose the active Nextcloud integration and
configure fallback delivery.

The page defaults to the active global default Nextcloud integration. Admins can select another active Nextcloud integration for this notification channel when needed.

The page does not ask for a Nextcloud base URL, API token, bot id, or bot secret. Those values belong to the Nextcloud integration settings.

Users can still configure personal Nextcloud Talk webhook URLs in their notification preferences.
When Bot API delivery is active, Nexum extracts the conversation token from a personal webhook URL
when possible and uses that as the user's conversation override. When webhook delivery is used,
personal webhook URLs take priority over the system default webhook URL.

## Bot API Delivery

Bot API delivery is automatic when the selected active Nextcloud connection has bot settings saved.

The delivery flow is:

1. The notification class provides `toNextcloudTalk`.
2. The channel resolves the active Nextcloud connection.
3. If the connection has a Talk bot id and secret, `NextcloudTalkClient` sends an HMAC-signed message.
4. If no bot is configured, the channel falls back to webhook delivery.

Bot API messages support richer Markdown formatting, details, links, reference ids, silent messages,
and future inbound command processing.

## Webhook Fallback

Webhook delivery posts `{ "message": "..." }` to the configured URL. This mode is simpler and has
less context than Bot API delivery.

Use webhook fallback when the Nextcloud server does not support Talk Bot API or when the bot has not
been installed yet.

## Testing

Use Test Connection from the channel settings page after saving fallback settings. Use Test Bot
Message from the Nextcloud connection detail page after saving Talk bot settings.

The channel test requires:

- An active Nextcloud integration.
- A valid default webhook URL when testing fallback delivery.

If required configuration is missing, the test reports the configuration issue instead of attempting
delivery.
