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

## Official API configuration and test (2026-10-27)

Availability: Dev implementation; production deployment and live sends remain separate.

1. Read `/api/v1/nextcloud/connections/{connection}/talk-bot` for safe configuration markers.
2. PATCH the same URL with the required `nextcloud.talk.write` grant and native connection
   rights. A supplied secret also requires `nextcloud.credentials.write`; omission preserves it.
3. For a separately authorized test, confirm the exact connection/default conversation.
4. POST `/talk-bot/test` with `confirm: true`, the connection ETag and an idempotency key.
   This requires native Talk rights, `nextcloud.talk.execute`, `nextcloud.providers.execute`
   and an enabled API provider-execution switch. API availability alone never turns it on.
5. Read the operation receipt. `acknowledged` contains the provider message ID and reference;
   `delivery_verified: false` means recipient receipt has not been proven.

HTTP success with an OCS failure is not success. Unknown remote outcomes stay `unknown` and
are never resent with the same key. Inspect the actual provider conversation before retrying
with a new intent. Raw remote errors and shared secrets are not exposed. The independent signed
inbound webhook and Notification-owned channel preferences remain in their existing domains.
The proposed multi-destination service-user setup in Issue #205 is a separate feature.


## Ordinary notification setup (Issue #205, 2026-10-28)

Availability: Dev implementation; Main verification is in progress and production is unchanged.
For ordinary notifications, use the connection's Talk Notifications panel and Notification
Channels to select an exact service-user conversation. A Bot ID/secret or webhook URL is not
required. The Bot setup above remains an advanced compatibility option; HTTP acceptance alone
does not verify delivery. See Nextcloud Admin Setup for target selection, safe tests and migration
of existing personal legacy targets. HR-2026-10-28-TALK-DESTINATIONS remains Pending.
