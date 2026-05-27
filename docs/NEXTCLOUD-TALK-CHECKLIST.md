# Nextcloud Talk Bot — Go-Live Checklist

## Prerequisites (before the bot can send messages)

- [ ] **Nexum live instance updated** — Svein must deploy the `feature/nextcloud-talk-integration` branch (or merge into `Dev`/`main`) before the admin UI, migration, or Talk bot fields exist on the live server.
- [ ] **Run migration** — `php artisan migrate` on the live Nexum instance to add `talk_bot_id`, `talk_bot_secret`, `talk_default_conversation_token`, and `talk_bot_features` columns to `nextcloud_connections`.

## Nextcloud Server Setup (after Nexum is updated)

1. **Install the Talk bot:**
   ```bash
   sudo -u www-data php occ talk:bot:install \
     "Nexum PSA" \
     "<choose-a-strong-secret>" \
     "https://<nexum-public-url>/api/nextcloud/talk/webhook" \
     "https://<nextcloud-url>"
   ```
   > The webhook URL is for **inbound** messages (Talk → Nexum). If the inbound controller isn't built yet, use a placeholder — outbound messages still work without it.

2. **Note the Bot ID:**
   ```bash
   sudo -u www-data php occ talk:bot:list
   ```

3. **Enable the bot in a Talk conversation** — Talk UI → conversation settings → Bots → enable "Nexum PSA"

4. **Get the conversation token** — visible in the Talk URL: `https://nextcloud.example.com/call/<token>`

## Nexum Admin Setup (after bot is registered)

1. Go to **System → Nextcloud → Connection settings → Talk Bot Configuration**
2. Enter the **Bot ID** (from step 2 above)
3. Enter the **Bot Shared Secret** (from step 1 above)
4. Enter the **Default Conversation Token** (from step 4 above)
5. Click **Test Bot Message** to verify

## What works immediately

- **Outbound notifications** (Nexum → Talk): ticket status changes, assignments, comments, SLA warnings, asset alerts
- Rich Markdown formatting with title, details, links, reference IDs
- HMAC-SHA256 signed messages

## What's built but not yet wired

- **Inbound commands** (Talk → Nexum): `NextcloudTalkClient.verifyIncomingSignature()` and `parseIncomingMessage()` are implemented, but there's no route/controller/dispatcher yet. A future task will add:
  - `POST /api/nextcloud/talk/webhook` route
  - Controller to verify signature and parse commands
  - Command dispatcher for `!status`, `!assign`, etc.

## Deployment dependency

**The live Nexum instance must be updated before any Nextcloud server configuration.** The migration, model fields, admin UI, and Talk client service must all exist on the live server first. Until then, the Talk bot configuration section won't appear in the admin UI and the notification channel won't use Bot API delivery.