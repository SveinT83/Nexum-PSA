# ADR: Email Attachment Quarantine With Local ClamAV

Status: Accepted
Date: 2026-08-16
Decision Makers: Svein / Codex
Related RFC: `../rfc/2026-07-04-mail-module-full-email-client.md`
Related ADRs:
- `2026-08-11-email-owned-mail-client-domain.md`
- `2026-08-11-email-mailbox-access-and-rule-authority.md`
Feature Slice: `../feature-slices/2026-08-16-email-mail-attachment-malware-quarantine.md`

## Context

Email currently stores accepted inbound and draft attachment bytes directly in the ordinary private
attachment tree. The Mail download route enforces account/placement/path authorization and forces a
non-inline octet-stream response, but it has no malware/content-security result. There is no ClamAV
binary, daemon or scanner service active on Dev. Known malicious, encrypted, unsupported, oversized,
indeterminate and not-yet-scanned content therefore cannot be distinguished at the normal Mail,
Ticket, search, rule, extraction or AI boundaries required by the accepted Mail RFC.

The scanner choice must not send mailbox content to an external service, make inbound synchronization
depend on scanner availability, allow an unsafe file into its final path before a result, or treat a
filename/MIME type as clean evidence. It must support exact-content idempotency, signature database
versioning, bounded retries, operational health and an explicit security incident trail.

## Decision

Use a locally supervised ClamAV `clamd` daemon over a Unix-domain socket as Nexum's first attachment
malware engine. Define an internal content-security scanner contract and versioned policy/result
model so the Email boundary does not depend on a third-party PHP package or ClamAV-specific result
strings. External/cloud scanning is not authorized by this ADR.

### Quarantine First

All new inbound, recovered, provider-Draft and user-uploaded Mail attachment bytes are written first
to a private `email/quarantine` tree using the existing Email private-storage ownership/mode/ACL
contract. The metadata state starts `pending`; ordinary attachment paths, preview, download, content
extraction, indexing, rules, AI, outbound send, Ticket capture and file-provider handoff cannot use
the bytes.

A bounded `email-security` queue job streams the exact stored file to `clamd` through its local Unix
socket, records content checksum/size plus scanner engine/signature/policy versions, then atomically:

- promotes an exact clean file to the normal private attachment tree and marks it `clean`;
- leaves malicious/indeterminate/unsupported/encrypted/oversized/error content in quarantine with a
  typed non-clean state; or
- records `scanner_unavailable` and schedules bounded retry without discarding message or file.

Promotion requires the file checksum/size and attachment optimistic version to still match the scan
claim. Stale results cannot bless changed bytes. Duplicate content may reuse a clean result only under
the same current policy and accepted scanner-signature floor, but each attachment retains its own
authorization/lifecycle state.

### Fail-Closed Consumption

Inline preview, ordinary download, extraction/indexing, deterministic rules, AI, outbound send,
Ticket capture/portal publication and Documentation/file-provider handoff require current `clean`
evidence for the exact content hash. Missing/stale/non-clean evidence is unavailable, not implicitly
safe. Inbound header/body synchronization remains successful and visibly reports attachment state.

Known malicious content has no application download route. Installation policy may enable a separate
permissioned risk-accepted raw download for encrypted, unsupported, oversized, indeterminate or
not-yet-scanned content, but never for `malicious`. That path requires an exact placement/account
authorization, dedicated permission, short-lived single-use warning confirmation, forced attachment,
octet-stream, `nosniff`, `private, no-store`, and immutable audit before bytes leave storage.

### Operations And Trust Boundary

`clamd` runs locally under a dedicated service identity, binds no public TCP port, reads files only
through the scanner stream/socket contract, and cannot write the attachment tree. FreshClam updates
signatures under a supervised service with observable age/health. Email workers do not run shell
commands or interpolate filenames into a command line.

Configuration specifies only the approved Unix socket, connection/read/deadline byte limits,
signature-age floor and policy version. Socket paths must be absolute, locally owned, non-symlinked
and allowlisted; runtime revalidates them before use. Scan metadata/logs contain safe IDs, hashes,
versions, sizes, states and exception class/code, never content, filename, address, subject, private
path, provider response or credentials.

## Rationale

- Local ClamAV avoids a new external mailbox-content processor and data-egress/legal dependency.
- Quarantine-first storage prevents a race in which unscanned bytes become ordinarily downloadable.
- A product-neutral scanner contract keeps policy and evidence stable if the engine changes later.
- Durable exact-content results and CAS promotion make retry, worker loss and signature updates
  deterministic.
- Scanner failure remains visible without blocking safe message synchronization.

## Alternatives Considered

- **Cloud malware scanning.** Rejected without a separate provider/data-egress ADR, tenant/legal
  basis, encryption, retention, regional and cost controls.
- **Run `clamscan` as a shell command per file.** Rejected because process startup and command/path
  handling are harder to bound and supervise at Mail volume.
- **Scan after ordinary storage/download.** Rejected because it leaves an unsafe availability window.
- **MIME/extension allowlist only.** Retained as one content-policy input, but rejected as malware
  evidence because both values are attacker-controlled/incomplete.
- **Block all attachments whenever scanner is unavailable.** Rejected for message synchronization;
  bytes remain quarantined and unavailable while header/body mail continues.

## Consequences

Positive:

- Mail has one explicit clean-evidence boundary shared by UI, API, search, rules, AI, send, Ticket
  and later file-provider actions.
- No attachment content leaves the installation for scanning.
- Malicious and uncertain content remain recoverable for controlled incident/lifecycle handling
  without ordinary user exposure.

Negative:

- Dev/production require ClamAV packages, signatures, disk capacity, a supervised daemon/updater and
  an `email-security` worker.
- Existing attachments require a bounded backfill scan before ordinary access can claim completion.
- Signature updates can make old evidence stale under policy and create a visible rescan backlog.
- Quarantine adds storage/move/retention complexity and human incident-review requirements.

## Follow-Up

- Implement the linked order-21 Feature Slice and keep `HR-2026-08-16-021` Pending until real daemon,
  outage, malicious/encrypted/oversized, raw-warning, ACL, queue and cross-surface behavior are reviewed.
- Install/configure ClamAV only in a controlled operational step; no migration or deploy starts a
  scan automatically.
- Revisit a different scanner through a new ADR rather than silently changing result semantics.
