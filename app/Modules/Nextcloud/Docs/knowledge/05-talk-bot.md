Nextcloud Talk delivery has two supported modes. The preferred mode is the Talk Bot API configured on
the Nextcloud connection. Legacy incoming webhooks remain as a fallback through the Notification
channel settings.

## Ownership

Nextcloud owns the server connection, service credentials, Talk bot id, Talk bot shared secret,
default conversation token, bot features, and inbound signature verification helpers.

Notification owns notification preferences, notification channel enablement, and fallback webhook
delivery. It does not own the Nextcloud server URL or bot secret.

## Preferred Bot API Setup

Use the Nextcloud server command line to install a Talk bot:

```bash
sudo -u www-data php occ talk:bot:install \
  "Nexum PSA" \
  "<strong-shared-secret>" \
  "https://<nexum-public-url>/api/nextcloud/talk/webhook" \
  "https://<nextcloud-url>"
```

Then list bots and note the numeric bot id:

```bash
sudo -u www-data php occ talk:bot:list
```

In Nexum, open the relevant connection under `Admin -> System -> Nextcloud` and use the `Talk Bot
Configuration` card:

- `Bot ID`: numeric id from `talk:bot:list`.
- `Bot Shared Secret`: the shared secret used during `talk:bot:install`.
- `Default Conversation Token`: the Talk conversation token from the Talk room URL.
- `Bot Features`: enabled capabilities that match the configured Talk bot.

The secret is stored encrypted and is never displayed back in the UI. Leaving the secret field blank
while editing keeps the existing secret.

## Delivery Resolution

When a notification supports Nextcloud Talk, delivery is resolved in this order:

1. The Notification channel must be enabled and connected to an active Nextcloud connection.
2. If the selected active connection has a bot id and secret, Nexum sends through the Talk Bot API.
3. The conversation token is resolved from per-user Talk preference, Notification channel default
   token, then the Nextcloud connection default conversation token.
4. If no bot is configured, Nexum falls back to the legacy webhook URL.

Bot API delivery supports signed messages, Markdown formatting, reference ids, silent messages, and
future command processing. Webhook delivery only sends a plain message payload.

## Inbound Commands

`NextcloudTalkClient` can verify incoming signatures and parse incoming bot payloads. The inbound
route/controller/dispatcher is not enabled yet. Until that endpoint exists, outbound bot messages can
still work, but Talk-to-Nexum commands are not active.

When inbound commands are implemented, the webhook endpoint must verify the HMAC signature before
parsing commands such as status, assignment, or future approval actions.

## Operational Notes

Run migrations before configuring Talk bot fields on an existing install, because the bot columns
live on `nextcloud_connections`.

Use `Test Bot Message` from the connection detail page after saving bot settings. If the test fails,
check:

- Nextcloud connection health.
- Bot id and shared secret.
- Conversation token.
- Talk bot enablement inside the conversation.
- Server support for the Talk Bot API capability.
