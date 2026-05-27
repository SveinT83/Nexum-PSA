# Nextcloud Talk Bot - Standard-Aligned Go-Live Checklist

This checklist is not a deployment approval by itself. It is the required sequence for making the
Talk Bot API integration production-ready while keeping the existing tdPSA module architecture,
Notification ownership, UI patterns, and Knowledge documentation intact.

## 1. Code Readiness Gate

Complete these items before any live Nextcloud server setup.

- [ ] **Route names render correctly** - Blade views must use the route names actually registered
  by the module route loader. Nextcloud admin routes are loaded under the `tech.` name prefix, so
  the Talk Bot test action must be referenced as `tech.admin.nextcloud.connections.test-talk-bot`.
- [ ] **Talk Bot settings have a valid save path** - The Talk Bot form must not post a partial
  payload into the full Nextcloud connection update validator unless it includes all required
  connection fields. Prefer a small dedicated controller action for Talk Bot settings.
- [ ] **Notification delivery respects the selected Nextcloud integration** - The Notification
  channel must use the configured `nextcloud_connection_id` when present, then fall back to the
  active global default connection. It must not silently choose the first active connection.
- [ ] **Webhook fallback remains compatible** - Existing system and per-user Talk webhook URLs
  must continue to work when no Talk Bot is configured.
- [ ] **Factories resolve through Laravel discovery** - Any factories used by
  `NextcloudConnection::factory()`, `NotificationChannel::factory()`, or similar model factory
  calls must live in namespaces Laravel can discover or be explicitly linked from the model.
- [ ] **Tests pass before deployment** - Run the relevant Talk and Nextcloud tests:

  ```bash
  HOME=/tmp php artisan test \
    tests/Unit/Modules/Nextcloud/Services/NextcloudTalkClientTest.php \
    tests/Unit/Modules/Notification/Channels/NextcloudTalkChannelTest.php \
    app/Modules/Nextcloud/Tests/Feature/NextcloudModuleTest.php
  ```

## 2. Architecture Standard

The implementation must follow the existing tdPSA domain boundaries.

- [ ] **Nextcloud owns provider-specific state** - `talk_bot_id`, encrypted `talk_bot_secret`,
  `talk_default_conversation_token`, Talk capabilities, and the Talk API client belong to the
  `app/Modules/Nextcloud` domain.
- [ ] **Notification owns delivery decisions** - `NextcloudTalkChannel` may choose Bot API or
  webhook delivery, but it should read connection ownership from Notification channel config rather
  than duplicating Nextcloud credentials.
- [ ] **No default route files for domain behavior** - Do not add Talk routes to `routes/web.php`
  or new route files under `routes/`. Domain routes belong in `app/Modules/Nextcloud/routes.php`.
- [ ] **Future inbound commands use module routes** - If inbound Talk commands are added later,
  the route/controller must be owned by the Nextcloud module or by a clearly justified command
  module, not by a loose default Laravel controller.
- [ ] **Secrets are never displayed back** - Bot shared secrets must be encrypted at rest and the
  UI must use an empty password input with "leave blank to keep existing" behavior.

## 3. UI Standard

The admin UI should stay consistent with tdPSA operational settings pages.

- [ ] **Use the existing Nextcloud admin surface** - Talk Bot configuration belongs in the
  Nextcloud connection detail/settings view, not in a separate generic admin page.
- [ ] **Keep cards compact and scannable** - The Talk Bot section should be a compact sibling card
  or accordion section, with a small status badge such as "Bot configured" or "No bot".
- [ ] **Avoid misleading setup text** - The UI must clearly distinguish Bot API setup from legacy
  webhook setup. Webhook fallback text belongs in Notification channel settings.
- [ ] **Button actions are explicit** - Use separate actions for "Save Talk Bot Settings" and
  "Test Bot Message"; the test action must not depend on an undefined route or a full-page form
  submit.
- [ ] **No secret leakage in errors or logs** - Failed tests may show status and short API detail,
  but must not log the bot secret or full signed payload.

## 4. Documentation Standard

- [ ] **Root docs explain implementation details** - Keep `docs/NEXTCLOUD-TALK-INTEGRATION.md`
  as the technical implementation and operations reference.
- [ ] **Knowledge docs are updated for operators** - Add or update focused pages under
  `app/Modules/Nextcloud/Docs/knowledge/` for:
  - Talk Bot overview and when to use Bot API versus webhook fallback.
  - Admin setup steps for Bot ID, shared secret, default conversation token, and testing.
  - Operational behavior, failure modes, and rollback to webhook fallback.
- [ ] **Knowledge article bodies do not repeat their page title as the first Markdown heading**
  when the Knowledge UI already renders the title.

## 5. Database And Migration

- [ ] **Run migration only after the code gate passes** - The live migration should add:
  - `talk_bot_id`
  - `talk_bot_secret`
  - `talk_default_conversation_token`
  - `talk_bot_features`
- [ ] **Verify encryption locally** - Confirm that `talk_bot_secret` is cast as `encrypted` on
  `NextcloudConnection` before storing live secrets.
- [ ] **Preserve existing data** - Existing Nextcloud connection rows and existing notification
  webhook configuration must remain usable after migration.

## 6. Nextcloud Server Setup

Run these steps only after the application code and tests are ready.

1. Install the Talk bot on the Nextcloud server:

   ```bash
   sudo -u www-data php occ talk:bot:install \
     "Nexum PSA" \
     "<choose-a-strong-secret>" \
     "https://<nexum-public-url>/api/nextcloud/talk/webhook" \
     "https://<nextcloud-url>"
   ```

   The webhook URL is for future inbound messages from Talk to Nexum. If the inbound controller is
   not implemented yet, use a placeholder URL and keep outbound Bot API testing in scope only.

2. Record the bot ID:

   ```bash
   sudo -u www-data php occ talk:bot:list
   ```

3. Enable the bot in the target Talk conversation.

4. Copy the conversation token from the Talk URL:

   ```text
   https://nextcloud.example.com/call/<token>
   ```

## 7. Nexum Admin Setup

1. Go to **System > Nextcloud > Connection settings > Talk Bot Configuration**.
2. Enter the Bot ID from `talk:bot:list`.
3. Enter the Bot Shared Secret from `talk:bot:install`.
4. Enter the Default Conversation Token.
5. Save Talk Bot Settings.
6. Click Test Bot Message.
7. Confirm that a message appears in the expected Talk conversation.
8. Verify that Notification channel fallback still works if Bot API fields are removed.

## 8. Go-Live Decision

The Talk Bot integration is ready for live use only when:

- [ ] The code readiness gate is complete.
- [ ] The relevant tests pass.
- [ ] Existing webhook behavior has been regression-tested.
- [ ] Knowledge documentation is updated.
- [ ] A rollback path is documented: clear Bot API fields and continue using the existing webhook
  delivery mode.
