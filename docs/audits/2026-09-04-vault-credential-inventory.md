# Vault Credential Source And Consumer Inventory

Status: Baseline accepted
Date: 2026-09-04
Parent: Discussion #279 and the Vault RFC

This inventory records secret owners and consumers without reading or reproducing credential values.
Every source requires its own preview, verification, cutover, read-back, rollback, observation, and
separate legacy-purge decision.

## Managed Sources

| Current owner/store | Secret material | Important consumers | Vault target |
| --- | --- | --- | --- |
| `integrations.secrets` | RMM API keys, BookStack token pair, CloudFactory token/webhook material, other provider secrets | Integration admin, RMM, BookStack, CloudFactory, jobs/sync | Integration retains Connection/endpoints/health; secret values become Vault references. |
| `ai_providers.secrets.api_key` | AI provider API key | AI chat, structured workloads, Lead Intelligence, Task, Storage, Marketing, Signal | Early migration candidate after the foundation and recovery gates. |
| `email_accounts.imap_secret` / `smtp_secret` | Account-owned IMAP/SMTP credentials | Polling, fetch, SMTP, reconciliation, Ticket, Notification, Marketing | Migrate last, one account at a time; Email retains account/endpoint/binding authority. |
| Retired Integration Email credential versions | Historical provider ciphertext | Testing-only compatibility lifecycle | Do not migrate as a normal source; the 2026-09-01 cutover destroyed duplicate provider ciphertext. |
| `nextcloud_connections` | Service password and Talk bot secret | Nextcloud read/Talk/admin/sync | Nextcloud retains Connection/user/scope; secret values move to Vault. |
| `nextcloud_user_credentials` | Per-user app password | Personal Nextcloud operations | Keep remote username and ownership in Nextcloud; move secret value. |
| `notification_channels.secrets` | API token | Notification channels and Nextcloud Talk | Move secret value; Notification retains channel behavior. |
| `notification_settings.nextcloud_talk_webhook_url` | Bearer-like webhook URL | Per-user notification | Classify as secret despite the field name and move/reference safely. |
| `nexum_relationships` | Reversible outbound token and webhook secret | Relationship HTTP/webhook/sync | Move reversible values; keep inbound verifier hashes in Relationship. |
| `telephony_tokens.token_value` | Reversible intake token | Telephony intake URL | Vault may own displayable value; keep lookup hash in Telephony. |

## Security Material That Does Not Become A Normal Vault Item

- User password hashes.
- Fortify two-factor secrets and recovery codes.
- Password-reset, Sanctum, invitation, session, and remember-token verifier material.
- Web Push endpoint/public key/auth transport records.
- `APP_KEY`, database credentials, VAPID private keys, and other bootstrap/infrastructure secrets.
- Processing claims, idempotency keys, execution fingerprints, and short-lived coordination tokens.

These remain in their owning security domains. Vault must not turn verifier-only or bootstrap
material into user-browsable credentials.

## Reusable Safety Patterns

- Credential lifecycle: `EmailProviderCredentialVersion`, Stage, Verify, Activate, Revoke, exact
  locking, and ciphertext destruction.
- Runtime material: `EmailProviderRuntimeCredentials` is short-lived, nonserializable, and redacted.
- Append-only audit: Email provider events use model and database update/delete protection.
- Approval: Ticket Workflow reviews bind an independent reviewer and invalidate stale evidence.
- Step-up/break-glass: Email emergency access provides narrow scope, expiry, permission, audit, and
  notification patterns, but Vault requires its own action-bound state.
- Secret filtering: Data Exchange exclusion, Email telemetry redaction, Ticket audit sanitization,
  and Storage AI minimization are useful negative controls.
- Private storage: Email path containment and OS-mode checks are reusable, but Vault payloads still
  require application encryption and a separate non-served storage root.

## Migration Risks

- Current ciphertext uses Laravel Crypt/`APP_KEY`; retain required previous keys until every
  backfill and rollback window is closed.
- Some legacy accessors collapse missing and corrupt ciphertext into `null`; Vault must preserve
  distinct fail-closed reason codes.
- Jobs must carry references, never decrypted material.
- Email's recent account-owned cutover must preserve binding versions, account locks, worker
  restart, endpoint probes, and rollback evidence.
- CloudFactory rotates short-lived tokens at runtime and needs a dedicated write/cutover design.
- Field names are insufficient for classification; bearer-like webhook URLs are credentials.
- Audit metadata requires recursive allowlisting and must not retain provider exceptions.
- The ordinary private filesystem can be served by framework features; Vault must use a distinct
  non-served encrypted path.

## Ordered Source Migration

1. Build the independent key provider, envelope encryption, immutable version, audit, permission,
   step-up, approval, and recovery foundations.
2. Add a reference/resolver contract with metrics and explicit authority state; do not delete any
   legacy field.
3. Pilot one non-production source after backup/restore proof.
4. Migrate AI provider credentials.
5. Migrate BookStack and RMM, then Nextcloud and Notification.
6. Migrate Relationship and Telephony.
7. Migrate CloudFactory with token-refresh and concurrent-write coverage.
8. Migrate Email accounts last, one verified account at a time.
9. Enable customer publication only after its own grant, step-up, audit, and privacy slice.
10. Purge legacy ciphertext only after observation, rollback expiry, runtime read-back, worker
    restart, backup evidence, and explicit destructive approval.
