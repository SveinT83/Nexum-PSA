# Nextcloud Talk Integration

## Overview

Nexum-PSA integrates with Nextcloud Talk for sending ticket notifications, SLA warnings, and asset alerts directly into Talk conversations. The integration supports two delivery modes, with automatic selection based on configuration.

## Delivery Modes

### 1. Bot API (preferred)

The Talk Bot API (NC 27.1+ / Talk 17.1+) sends **HMAC-SHA256 signed messages** to Talk conversations. This is the proper, secure way to post into Talk.

**Requirements:**
- Nextcloud 27.1+ with Talk 17.1+ and `bots-v1` capability
- A bot registered on the Nextcloud server
- Bot ID, shared secret, and target conversation token stored in Nexum

**How it works — outbound (Nexum → Talk):**

```
Nexum constructs message
  → NextcloudTalkClient generates HMAC-SHA256 signature
  → POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message
    Headers:
      X-Nextcloud-Talk-Random: <64-char random string>
      X-Nextcloud-Talk-Signature: <HMAC-SHA256 of random+body using bot secret>
      OCS-APIRequest: true
      Content-Type: application/json
    Body:
      {
        "message": "**Ticket TK-42 assigned to you**\n\n...",
        "referenceId": "ticket-assigned-TK-42-1700000000",
        "silent": false
      }
  → Talk verifies signature → message appears in conversation
```

**Features:**
- Rich Markdown formatting (bold, links, lists)
- Reference IDs for message deduplication
- Silent messages (no push notification, e.g. SLA warnings)
- Reply-to support for threaded messages
- Cryptographic verification — Talk rejects unsigned or incorrectly signed messages

### 2. Webhook (fallback/legacy)

Simple `POST { "message": "..." }` to a Talk webhook URL. Works with any Talk version but only supports plain text — no signing, no deduplication, no rich formatting.

**How it works:**

```
Nexum constructs plain text message
  → POST <webhook-url>
    Body: { "message": "Ticket TK-42 assigned to you" }
  → Talk delivers message (unverified, plain text only)
```

### Mode Selection

The `NextcloudTalkChannel` automatically selects the delivery mode:

| Condition | Mode |
|-----------|------|
| Connection has `talk_bot_id` + `talk_bot_secret` | Bot API |
| Only webhook URL configured | Webhook |
| Neither configured | Message skipped (logged) |

## Bot Setup on Nextcloud Server

### 1. Install the bot via OCC

```bash
# On the Nextcloud server:
sudo -u www-data php occ talk:bot:install \
  "Nexum PSA" \
  "<choose-a-strong-secret>" \
  "https://<nexum-url>/api/nextcloud/talk/webhook" \
  "https://<nextcloud-url>"
```

Parameters:
- **Name**: Display name in Talk (e.g. "Nexum PSA")
- **Secret**: A strong random string for HMAC signing. Generate with: `openssl rand -hex 32`
- **Webhook URL**: Nexum endpoint that receives incoming Talk messages (for future command processing). Can be a placeholder if inbound commands aren't needed yet.
- **Nextcloud URL**: The base URL of the Nextcloud server.

### 2. Note the Bot ID

```bash
sudo -u www-data php occ talk:bot:list
```

The output shows each bot's numeric ID, name, and status. Record the ID.

### 3. Enable the bot in a conversation

In Talk, open a conversation → Settings → Bots → enable "Nexum PSA".

### 4. Get the conversation token

The conversation token appears in the URL when viewing a conversation in Talk:
```
https://nextcloud.example.com/call/n3xtc10ud
                                ^^^^^^^^^^
                                This is the token
```

Alternatively, use the API:
```bash
curl -u admin:password \
  -H "OCS-APIRequest: true" \
  "https://nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/room" \
  | jq '.ocs.data[] | {token, name, displayName}'
```

### 5. Configure Nexum

In Nexum admin → System → Nextcloud → Connection settings → **Talk Bot Configuration**:

| Field | Value |
|-------|-------|
| Bot ID | Numeric ID from `talk:bot:list` |
| Bot Shared Secret | The secret chosen in step 1 |
| Default Conversation Token | Token from step 4 |
| Bot Features | Check "Reaction support" if enabled in Talk |

Click **Test Bot Message** to verify the integration.

## Architecture

### Service: `NextcloudTalkClient`

Located at `app/Modules/Nextcloud/Services/NextcloudTalkClient.php`.

| Method | Purpose |
|--------|---------|
| `sendBotMessage()` | Send signed message via Bot API |
| `sendChatMessage()` | Send message via OCS Chat API (user auth) |
| `listConversations()` | List available Talk rooms |
| `getConversation()` | Get details for a specific room |
| `createConversation()` | Create a new Talk room |
| `verifyIncomingSignature()` | Verify HMAC signature on incoming webhooks |
| `parseIncomingMessage()` | Parse Activity Streams 2.0 payload from Talk |
| `listInstalledBots()` | List bots on the Nextcloud server (admin) |
| `supportsBots()` | Check if server has `bots-v1` capability |

### Channel: `NextcloudTalkChannel`

Located at `app/Modules/Notification/Channels/NextcloudTalkChannel.php`.

Resolves the delivery mode and target conversation:

1. Checks if the `nextcloud_talk` notification channel is enabled system-wide
2. Checks if an active Nextcloud connection exists
3. If connection has bot config → sends via Bot API
4. If webhook URL available → sends via webhook
5. Otherwise → skips

**Conversation token resolution order:**
1. Per-user `nextcloud_talk_webhook_url` (extracts token from URL pattern `/room/{token}/webhook`)
2. System-wide `default_conversation_token` in notification channel config
3. Connection-level `talk_default_conversation_token`

### Model: `NextcloudConnection`

New fields added:

| Column | Type | Purpose |
|--------|------|---------|
| `talk_bot_id` | unsigned bigint, nullable | Bot ID from `talk:bot:list` |
| `talk_bot_secret` | text, encrypted | HMAC-SHA256 shared secret |
| `talk_default_conversation_token` | varchar(64), nullable | Default target conversation |
| `talk_bot_features` | JSON, nullable | Feature flags (`reaction`, `no-setup`) |

Helper methods:
- `hasTalkBot()` — True if bot ID and secret are configured
- `hasTalkBotFeature($feature)` — Check a specific feature flag
- `getTalkBotSecret()` — Get decrypted bot secret

### Notification Payload Format

All 5 notification classes now return rich `toNextcloudTalk()` arrays:

```php
[
    'title' => 'Ticket TK-42: open → in_progress',
    'message' => '**Printer on fire**',
    'details' => [
        'Status' => 'open → in_progress',
        'Changed by' => 'Jo',
        'Priority' => 'High',
        'Client' => 'Acme Corp',
    ],
    'url' => 'https://nexum.example.com/tickets/TK-42',
    'urlLabel' => 'View Ticket',
    'referenceId' => 'ticket-status-TK-42-in_progress-1700000000',
    'silent' => false,  // true for SLA warnings
]
```

The `NextcloudTalkChannel.formatMessage()` method renders this into Markdown:

```
**Ticket TK-42: open → in_progress**

**Printer on fire**

- **Status:** open → in_progress
- **Changed by:** Jo
- **Priority:** High
- **Client:** Acme Corp

[→ View Ticket](https://nexum.example.com/tickets/TK-42)
```

## Incoming Messages (Future)

The Talk Bot API supports receiving messages from users via the webhook URL registered during `talk:bot:install`. This enables command processing like:

- `!status TK-42` — Look up ticket status
- `!assign TK-42 @username` — Assign a ticket
- `!close TK-42` — Close a ticket

The `NextcloudTalkClient.verifyIncomingSignature()` and `parseIncomingMessage()` methods are already built for this. A controller route and command dispatcher would be needed to complete it.

### Incoming message flow (planned)

```
Talk user sends message in conversation with bot
  → Talk POSTs Activity Streams 2.0 payload to Nexum webhook URL
  → Controller verifies HMAC signature using shared secret
  → NextcloudTalkClient.parseIncomingMessage() extracts command
  → Command dispatcher routes to appropriate handler
  → Handler sends response via sendBotMessage()
```

## Bot API vs Webhook Comparison

| Feature | Bot API | Webhook |
|---------|---------|---------|
| Message signing | ✅ HMAC-SHA256 | ❌ None |
| Rich formatting | ✅ Markdown | ❌ Plain text |
| Reference IDs | ✅ Deduplication | ❌ None |
| Silent messages | ✅ No push notification | ❌ Always notifies |
| Reply threading | ✅ `replyTo` | ❌ None |
| Bidirectional | ✅ Receives commands | ❌ Outbound only |
| Minimum NC version | 27.1 / Talk 17.1 | Any |
| Setup complexity | Requires `occ talk:bot:install` | Just paste URL |

## HMAC-SHA256 Signing Protocol

Every outbound Bot API request includes two headers:

1. **X-Nextcloud-Talk-Random**: A 64-character cryptographically random string (nonce)
2. **X-Nextcloud-Talk-Signature**: `HMAC-SHA256(secret, random + requestBody)` in lowercase hex

The signature is computed as:

```php
$random = random_bytes(48); // 64 chars after base64
$signature = hash_hmac('sha256', $random . $jsonBody, $secret);
```

Talk verifies this on receipt. If the signature doesn't match, the message is rejected with HTTP 400.

Incoming messages (from Talk to Nexum) use the same signing protocol — Nexum must verify the signature before trusting the payload.

## Database Migration

The migration `2026_05_27_100000_add_talk_bot_fields_to_nextcloud_connections` adds four columns to `nextcloud_connections`:

- `talk_bot_id` (unsigned bigint, nullable)
- `talk_bot_secret` (text, nullable, cast as `encrypted`)
- `talk_default_conversation_token` (varchar 64, nullable)
- `talk_bot_features` (JSON, nullable)

Run with: `php artisan migrate`

## Testing

Unit tests cover:

- `NextcloudTalkClientTest` (9 tests): Bot message sending, chat message, conversation listing, signature verification, incoming message parsing, capability checking, error handling, optional fields
- `NextcloudTalkChannelTest` (6 tests): Bot API vs webhook delivery, disabled channel, no active connection, per-user conversation tokens, rich message formatting

Run with: `php artisan test --filter=NextcloudTalk`