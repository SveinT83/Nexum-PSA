# Web Push Deployment And Operations

This runbook covers the Notification-owned Web Push channel, device foundation, inbound Email
business-event delivery, and source read-sync slices.

Production rollout remains blocked until named human browser/device review is complete. Keep
`WEBPUSH_ENABLED=false` until the environment checks below pass.

## Prerequisites

- The deployed PHP runtime satisfies the locked Composer platform and has cURL/OpenSSL support.
- Nexum is served over HTTPS at its canonical origin.
- The database is backed up and migrations can run normally.
- The existing shared `/sw.js` is reachable without authentication.
- A Laravel queue worker is running and can be restarted after deploy.
- The external once-per-minute Email poller/scheduler path and the queue that handles `email` and
  `default` jobs are verified separately.
- Stable VAPID keys and a stable HTTPS or `mailto:` subject can be stored in the environment's
  approved secret store.

For Dev browser verification, use a real trusted HTTPS vhost and the checklist in
`docs/deployment/dev-https-pwa-vhost.md`. Service Worker registration and Web Push permission checks
are not valid final acceptance when the browser rejects the certificate.

The VAPID private key is a long-lived signing secret. Do not print it in terminals captured by
automation, commit it, copy it into documentation, or rotate it during a routine deploy. Replacing
the VAPID key pair invalidates existing browser subscriptions and requires users to register again.

## First Environment Setup

Install the locked dependencies with the deployment PHP runtime:

```bash
composer install --no-dev --classmap-authoritative
```

Generate one key pair directly in the target environment without `--show`:

```bash
php artisan webpush:vapid
```

The command updates the environment file on the target host. In centrally managed environments,
generate the keys through an approved secret-management workflow and set them without displaying
the private value in logs.

Set:

```dotenv
WEBPUSH_ENABLED=false
VAPID_SUBJECT=mailto:operations@example.invalid
VAPID_PUBLIC_KEY=<managed-public-key>
VAPID_PRIVATE_KEY=<managed-private-key>
```

Replace the example subject with the installation's stable operational identity. Leave the global
switch false during migration and initial verification.

## Deploy

1. Put the application into the installation's normal maintenance window where required.
2. Install the locked Composer dependencies.
3. Run the additive migration:

   ```bash
   php artisan migrate --force
   ```

4. Clear cached configuration and application caches:

   ```bash
   php artisan optimize:clear
   ```

5. Restart long-running queue workers so they load the new classes and configuration:

   ```bash
   php artisan queue:restart
   ```

6. Verify the queue supervisor/system service starts replacement workers.
7. Verify `/sw.js`, the PWA manifest, HTTPS, and the existing offline page.
8. Confirm Admin > Notification channels reports Web Push unavailable while the switch is false.
9. Set `WEBPUSH_ENABLED=true` only in the approved Dev/test environment.
10. Clear configuration cache and restart queue workers again.
11. Confirm the admin readiness view reports Ready without exposing any private key.

Inbound Email and Ticket customer-reply event rows default to in-app/database delivery. Email,
Nextcloud Talk, and Web Push remain disabled until each user explicitly enables the channel for the
event type.

## Dev Smoke Test

Use a dedicated internal test user and supported browser:

1. Open Profile > Notifications over HTTPS.
2. Confirm the browser does not prompt before Enable on this device is selected.
3. Register the device and confirm only its safe summary appears.
4. Send the generic current-device test and confirm exactly that device receives it.
5. Click the alert and confirm Nexum focuses/opens Profile > Notifications.
6. Register a second device, revoke one remotely, and confirm the other remains.
7. Sign out and confirm the registered device remains in the inventory after signing in again.
8. Disable the test user through the canonical User Management action and confirm all devices are
   removed.
9. Test the existing offline navigation fallback after the service-worker update.

For inbound Email/Ticket reply delivery:

1. Enable Web Push for Customer reply on my Tickets on one registered owner device.
2. Send a safe inbound reply that links to a Ticket owned by that user.
3. Confirm one in-app notification and one visible browser push are created.
4. Repeat the same Email rule/job path and confirm no duplicate notification appears.
5. Open the push or the Ticket directly and confirm only the matching canonical notification is
   marked read.
6. Confirm `tickets.is_unread`, `ticket_messages.read_at`, and Email workflow state are unchanged.
7. Enable New inbound Email for an authorized inbox subscriber and repeat with one unlinked Email.

Review queue logs only through normal sanitized application output. Push endpoints, subscription
keys, auth tokens, private VAPID material, and payload bodies must not be logged.

## Operational Checks

Readiness requires all of these configuration values:

- `WEBPUSH_ENABLED=true`
- `VAPID_SUBJECT` using HTTPS or `mailto:`
- non-empty `VAPID_PUBLIC_KEY`
- non-empty `VAPID_PRIVATE_KEY`

A queued test requires a running queue worker. Inbound Email delivery additionally depends on the
Email poller/scheduler path, Email fetch/processing queues, routing, and recipient resolution;
`schedule:list` alone does not prove the runtime is active.

Provider handling:

- HTTP 404/410 removes and audits the expired subscription without retry.
- HTTP 429, 5xx, timeout, or no response uses at most three queued attempts with bounded
  exponential backoff and jitter.
- Other permanent failures are logged with only the public subscription identifier and status.

The environment-wide kill switch blocks new registration and delivery. It does not delete device
registrations, which makes a temporary incident shutdown reversible.

## Rollback

For an operational incident:

1. Set `WEBPUSH_ENABLED=false`.
2. Run `php artisan optimize:clear`.
3. Run `php artisan queue:restart`.
4. Confirm readiness reports Disabled and canonical in-app notifications continue.

Prefer leaving the additive tables and existing subscriptions intact while diagnosing. A normal
code rollback can then be deployed without rotating VAPID keys.

Only roll back the database migration when the data is intentionally disposable and no deployed
code still reads the Web Push columns or tables:

```bash
php artisan migrate:rollback --step=1 --force
php artisan optimize:clear
php artisan queue:restart
```

That rollback deletes registered subscriptions and their lifecycle audit records and removes the
Web Push preference columns. A later rollback step removes canonical delivery identities and inbound
Email scope rows. Record explicit approval and a backup before using it.

## Human Review

The browser/device review is tracked as `HR-2026-07-24-001` and inbound Email delivery/read-sync
review is tracked as `HR-2026-08-11-002` in `docs/human-review.md`. Automated tests do not close
those reviews. A named reviewer must complete the listed browser, privacy, lifecycle, delivery, and
offline checks before their status changes to Reviewed.
