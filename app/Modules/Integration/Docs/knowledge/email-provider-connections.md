Manage Email provider connections from **Admin > System > Integrations > Email providers**. This is
the only place that accepts provider hostnames, usernames, or passwords. Email account settings show
only a safe provider label, source, readiness, and capabilities.

## Permissions

- `integration.email_provider_manage` lets an active Admin or Superuser manage public Email provider
  connections.
- `integration.email_private_endpoint_manage` is Superuser-only and is additionally required for an
  approved private/internal endpoint.
- Preview, stage, Verify, cutover, and rollback also require `email.mailbox_sync_manage`.
- Binding a provider to a mailbox also requires `email.account_manage`.
- `system.telescope_view` is Superuser-only and gates the complete Telescope UI. Email-provider
  authority alone does not reveal cross-domain telemetry.

Account configuration authority does not grant mailbox content, attachment, raw-source, search,
conversation, or send access.

## Create And Activate A Provider

Create one independent provider record for one endpoint and username identity. Enter a safe label,
the IMAP and SMTP settings, and new passwords. Existing credentials are never rendered back.

Supported standard transports are:

| Protocol | Port | Required transport |
| --- | ---: | --- |
| IMAP | 993 | Implicit TLS |
| IMAP | 143 | STARTTLS |
| SMTP | 465 | Implicit TLS |
| SMTP | 587 | STARTTLS |

Only password authentication is supported in this lifecycle. OAuth, certificate bypass,
self-signed certificates, plaintext transport, and arbitrary custom ports are not available. A
non-standard port must match one uniquely named installation policy in
`email_provider_security.additional_endpoints`.

Saving creates credential version 1 in **Staged** state. It is not usable yet:

1. Select **Verify**. This is the only action that resolves DNS and authenticates to the provider.
2. Review the sanitized result. Raw provider responses, endpoints, usernames, passwords, pinned
   addresses, and ciphertext are not displayed or retained in the event ledger.
3. Select **Activate** only for the exact verified version. Email accounts can bind only to an
   active, exactly verified provider.

Verification uses a bounded deadline and one owner lease. Every DNS answer must be acceptable; a
mixed public/private or otherwise unsafe set rejects the complete connection. Nexum pins one approved
address but keeps the original normalized hostname for SNI and certificate verification, requires
TLS 1.2 or newer, and authenticates only after TLS is established.

## Approved Private Endpoints

Private endpoints are unavailable until the installation defines a named CIDR group, for example:

```php
'trusted_private_cidrs' => [
    'mail_cluster' => ['10.20.0.0/16', 'fd20:30::/48'],
],
```

An active Superuser must select the exact name and record a reason. The resolved address must fall
inside that named range. Loopback, link-local, metadata, unspecified, multicast, documentation,
benchmark, reserved, and other always-denied destinations stay blocked even when private trust is
selected. Admins without private-endpoint permission cannot list, bind, test, stage, verify, or
activate such a connection.

## Rotate Or Revoke

**Stage rotation** accepts only new IMAP and SMTP passwords and preserves both usernames. Verify and
activate the staged version explicitly. Activation retires the previous version and destroys its
encrypted secrets. Work frozen against an old account binding fails stale before provider I/O;
ordinary secret rotation on the same binding resolves the current active version at execution.

**Revoke** destroys the selected local ciphertext and blocks new runtime authentication. It records
only the local lifecycle result; it does not claim the password was revoked at the provider. Rotate
or revoke only after account provider work is paused/drained and all durable Email operations are
resolved.

A username or endpoint change is a new provider identity. Create a new connection and use the
reviewed binding/re-baseline workflow. Nexum rejects a username change disguised as a rotation,
including after a staged version or revoked history exists.

## Migrate A Legacy Email Account

Existing accounts remain `legacy` until every explicit step succeeds. Never run a broad cutover.

1. Select exact legacy accounts and create a read-only migration preview.
2. Review its account scope and blockers. Preview reads no provider and exposes no secret.
3. Choose public or approved named-private trust for each item and **Stage locally**. Staging locks
   the exact source fingerprint, decrypts and re-encrypts only in process, and performs no DNS,
   provider call, send, mailbox mutation, deduplication, or source switch.
4. Verify each staged provider separately.
5. Activate the exact verified version, then **Pause & drain** the account.
6. Preview cutover readiness. Every Drafts/Sent/remote operation, import, cursor re-baseline,
   inventory, cleanup, reconciliation, and IDLE presence must be resolved.
7. Apply the exact cutover. It changes only the account provider reference and source.
8. Resume the account and perform the named human smoke checks.

Rollback is available only inside the declared window while the original legacy ciphertext is
intact and no later rotation, revocation, purge, rebinding, or unresolved provider work exists.
Legacy-secret destruction is not part of this rollout. Purge readiness requires a later named human
review and backup/recovery evidence.

## Deployment And Historical Telescope Data

Apply migrations `2026_08_16_112000` through `117000`, seed permissions and roles, clear caches,
rebuild group-writable views, restart long-lived queue workers, and synchronize Integration, Email,
Notification, and User Management Knowledge. Additive deployment leaves existing accounts on their
legacy source and makes no provider call.

Before `/telescope` is opened after this rollout, inventory historical provider-sensitive entries:

```bash
php artisan email-provider:telescope-remediate --limit=20000
```

The command prints counts, sequence bounds, and a SHA-256 cohort hash without printing entry content.
If a cohort exists, review the observability loss and purge only the exact unchanged cohort:

```bash
php artisan email-provider:telescope-remediate \
  --limit=20000 \
  --after-sequence=0 \
  --through-sequence=<reviewed-sequence> \
  --cohort-hash=<reviewed-hash> \
  --purge \
  --acknowledge-observability-loss
```

Continue from the reported `through-sequence` until a read-only preview reports zero matches. A
changed cohort fails without deletion. Do not run broad `telescope:clear`; unrelated diagnostic
history is outside this review.

Human review `HR-2026-08-16-006` remains Pending. No live provider verification, account cutover, or
legacy-secret purge is authorized merely because automated tests pass.
