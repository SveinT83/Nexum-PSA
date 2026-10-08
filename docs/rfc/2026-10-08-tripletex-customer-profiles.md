# RFC: Tripletex billing and Site address synchronization

Status: Approved
Date: 2026-10-08
Owner: Codex
Human review: HR-2026-10-08-TRIPLETEX-PROFILES

## Context and approval

Svein approved prefill plus ongoing two-way sync with Tripletex as primary authority, then explicitly
excluded primary contacts from ongoing sync. "Jepp. Iverksett" authorizes implementation now.
This extends the completed customer-number RFC; its unperformed human review remains open.
The existing GUI customer-sync switch authorizes this workflow; no extra .env gate.

## Goals and field ownership

Customer invoiceEmail <-> Client billing_email only. Never use invoiceEmail as primary contact email.
The explicitly bound Site address/co_address/zip/city/country <-> physicalAddress, falling back to
postalAddress at first binding when no business address is supplied. Keep this mapping stable.
Site name can initially use the street address; its subsequent name remains locally managed.
Customer email/mobile/phone and an unambiguous matching provider Contact may suggest primary-contact
details during creation only. User reviews these inputs. No inferred CEO role or ongoing Contact writes.
New customer creation may export the entered Site address and Billing Email, never primary contacts.
Names, other Sites, postal/delivery addresses not chosen for mapping, notes and deletions are outside scope.

## Proposed behavior

Load detailed prefill for only the explicitly selected customer. Add editable address inputs to New
Client. Preserve edited form inputs when a different customer is selected and invalidate old requests.
Bind a stable Site ID and encrypted field baseline per provider link. On initial historical binding,
Tripletex wins; never upload unknown historical local differences. Creation records a fresh provider
baseline so reviewed local differences can subsequently export.
Reconcile per field: unchanged/no-op; local-only change exports; provider-only imports; both changed
differently resolves to Tripletex and records field names in audit. Contacts are never read or written
by the reconciler. Blank values are explicit changes only after a baseline exists.
Persist an encrypted pending PUT snapshot before external writes. Use provider version + address
identity/version, allowlisted partial update, GET read-back and baseline recovery before retry.
Unknown outcomes are re-read, not blindly replayed. Recheck local state before committing imports.
Pause preserves baselines, pending evidence, identities and local edits.

## Impact and migration

Integration owns the provider transport and detailed lookup. Existing Clients owns creation inputs;
DataExchange owns reconciliation/profile evidence. Add an additive profile state table linked to
customer-number identities; it stores only bound Site ID, encrypted baseline/pending data and safe status.
No Contact schema or permission changes. Use existing create/view and integration admin permissions.
A bounded command every five minutes scans least-recently checked linked profiles with the same
account lock as number/time work. UI shows profile status/errors and supports explicit retry.
No new queue or frontend build. Existing external schedule:run runner must be verified.
No provider test customer mutation during implementation; real write acceptance remains human work.

## Feature slices

1. Done On Dev: provider profile contract, creation prefill/address persistence and tests.
2. Done On Dev: durable field reconciliation, conflict authority, pause/recovery and tests.
3. Done On Dev: bounded scheduler, status/retry UI, documentation, Dev migration and verification.

## Testing and documentation

Synthetic HTTP and isolated SQLite tests cover field isolation, contact exclusion, prefill/access,
creation, local/provider changes, simultaneous edits, blanks, version conflicts, crash recovery,
wrong company/number, missing Site and pause. Update Knowledge, TODO, ADR and human review.
Production promotion and deployment remain separately owned by Svein.

## Verification state

111 distinct Laravel tests and three JavaScript interaction checks pass. Scoped Dev migration ran
in batch 19. Read-only provider contracts and first inbound reconciliation passed with contact
tables unchanged. The existing OS cron ran the scheduled command at 15:25 UTC on 2026-10-08;
profile checked_at advanced to 15:25:11 UTC with synced status and no pending write.
Evidence: ../plans/2026-10-08-tripletex-customer-profile-verification.md.
HR-2026-10-08-TRIPLETEX-PROFILES was subsequently Reviewed by Svein Tore; real provider PUT/UI review is not claimed.

## Human approval and merge - 2026-10-08

Svein Tore explicitly approved the delivered customer scope and requested issue closure and Git
merge. HR-2026-10-08-TRIPLETEX-CUSTOMERS and HR-2026-10-08-TRIPLETEX-PROFILES are Reviewed.
This supersedes their earlier pending-review gate statements; automated/live-check limitations
remain recorded above without inventing additional test execution. Production deployment is separate.
Merge evidence: 2026-10-08-tripletex-customer-merge.md in docs/plans.
