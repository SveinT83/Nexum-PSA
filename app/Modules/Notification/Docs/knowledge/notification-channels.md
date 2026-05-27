Notification channels define how tdPSA delivers system notifications outside the in-app notification bell.

## Nextcloud Talk

Nextcloud Talk notifications use incoming webhook URLs for delivery.

The Notification module owns the webhook URL because it is the delivery target for notification messages. The Nextcloud module owns the Nextcloud integration itself, including base URL, credentials, sync settings, users, groups, calendars, and folder mappings.

Before the Nextcloud Talk channel can be enabled, at least one active Nextcloud integration must exist under Admin > Nextcloud. If no active integration exists, the channel remains disabled even if a webhook URL is saved.

## Configuration

Use Admin > Notification channels > Nextcloud Talk to choose the Nextcloud integration and configure the default webhook URL.

The page defaults to the active global default Nextcloud integration. Admins can select another active Nextcloud integration for this notification channel when needed.

The page does not ask for a Nextcloud base URL or API token. Those values belong to the Nextcloud integration settings.

Users can still configure personal Nextcloud Talk webhook URLs in their notification preferences. Personal webhook URLs take priority over the system default when the user has enabled Nextcloud Talk for the notification type.

## Testing

Use Test Connection from the channel settings page after saving the webhook URL.

The test requires both:

- An active Nextcloud integration.
- A valid default webhook URL.

If either is missing, the test reports the configuration issue instead of attempting delivery.
