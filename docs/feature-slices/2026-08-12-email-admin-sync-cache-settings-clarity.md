# Feature Slice: Email Admin Sync And Cache Settings Clarity

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex
Human review: `HR-2026-08-12-004`

## Goal

Make the existing Email admin configuration page match the finished Mail-client direction instead of
presenting legacy Ticket-ingest internals as ordinary e-post-client settings.

## User-Visible Behavior

`/tech/admin/settings/email/config` is now labelled as Email Sync & Cache Settings. The main sections
show provider sync, local cache and legacy cleanup, attachment import policy, system health, and
shortcuts. Advanced sender-authentication fields are moved into an Advanced Automation Trust section
with an Off/Configured badge.

Normal IMAP sync keeps provider mail on the server by default. Server cleanup after import is
explicit: accounts can use per-account `auto_delete`, or migrated legacy Ticket-ingest accounts can
use the `legacy_default` policy with the legacy global cleanup switch.

## Scope

- Rename and regroup the Email config page around sync, local cache, attachments, and health.
- Rename retention copy to local mail cache retention.
- Make the global delete-after-import switch default off for fresh configuration.
- Add `legacy_default` account cleanup policy for old global cleanup behavior.
- Preserve old behavior for existing Ticket-ingress accounts only when the legacy global cleanup
  setting was already enabled.
- Keep Proxmox Mail Gateway, DNS, SPF, DKIM, and DMARC as the ordinary mail-security boundary.
- Move authserv/receiving-hop settings into Advanced Automation Trust for sensitive automation only.

## Out Of Scope

- New `/tech/mail` mailbox workspace.
- Provider move/read/archive/trash UI.
- Historical import/rebaseline tooling.
- Replacing Proxmox Mail Gateway or DNS security controls.
- New supplier-order trust logic.

## Data Touched

- `common_settings` value `emailhub.delete_on_success` default only.
- `email_accounts.delete_policy` may be changed from `local_only` to `legacy_default` by migration
  only when global legacy cleanup was already enabled and the account is Ticket-ingress enabled.

## Tests

- Config page presents sync/cache/legacy cleanup and Advanced Automation Trust copy.
- Fresh config renders legacy server cleanup unchecked.
- Account form accepts and stores `legacy_default`.
- Existing trusted-auth validation still requires paired authserv and receiving-hop values.

## Done Criteria

- [x] Normal IMAP client behavior keeps provider mail on the server by default.
- [x] Legacy global cleanup is clearly labelled and scoped.
- [x] Account cleanup policy has an explicit `legacy_default` value for preserved legacy behavior.
- [x] Trusted sender-authentication fields are presented as advanced automation trust, not mail
  gateway security.
- [x] Email docs and Knowledge describe the same boundary.
- [x] Focused Email config/account/automation tests pass on Dev after migration.
