# Feature Slice: Email And Signal Supplier Order Handoff

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Connect stored inbound Email messages to deterministic Storage import proposals through an explicit,
trusted, idempotent Email-to-Signal-to-Storage handoff.

## User-Visible Behavior

Admins can configure an Email rule for selected supplier confirmations and a Signal rule/action that
queues a Storage import. A matching message is categorized/archived as configured, does not become a
normal Ticket, and appears once in Supplier Order Imports with trace links across Email, Signal, and
Storage.

Messages that do not match the explicit rule retain existing Email/Ticket behavior.

## Scope

- Define trusted receiving-hop/authserv configuration and normalized sender-authentication facts.
- Parse SPF/DKIM/DMARC/alignment only from `Authentication-Results` produced by configured trusted
  receiving infrastructure; visible From and forged headers are not trust evidence.
- Extend Email rule facts/actions only as needed for explicit supplier-order handoff and default
  Ticket-routing stop behavior.
- Emit one normalized `supplier_order_confirmation_received` Signal with minimized source facts and
  EmailMessage provenance.
- Add a validated Signal action that queues a Storage-owned import job with the stable Signal
  action key.
- Make Email, Signal, queue, and Storage retries converge on one import.
- Copy only the approved sanitized source snapshot/fingerprint into Storage. Keep raw EML and
  unrestricted headers in Email.
- Pin the active profile/policy version before deterministic extraction.
- Record cross-domain trace IDs and human-readable reason codes.
- Add a first fixture-backed Itegra rule/profile example as configuration, not hardcoded parsing.
- Respect the existing Signal rule execution and recovery contract.

## Out Of Scope

- Automatic PO creation.
- AI extraction or profile learning.
- Item mapping or creation.
- Broad emission of a Signal for every email.
- A generic Inbox AI button.
- Attachment/PDF extraction.
- Receipt or stock mutation.

## Data Touched

- Email rule action/fact JSON and trusted authentication configuration/facts.
- Existing `email_messages` source relationships; raw Email ownership remains unchanged.
- Signal type/action definitions, payload allowlist, execution audit, and tests.
- Storage import source/action/profile references and queued job state.
- Queue configuration and operational documentation.

## Permissions

- Existing Email rule management governs Email matching and ticket suppression.
- Existing `signal.rule.manage` governs Signal routing/action configuration.
- `storage.purchase_import_execute` is required by the configured action/service boundary.
- `storage.purchase_import_view` governs the Storage result.
- Signal permissions never imply `storage.purchase_manage`.

## Tests

- Trusted versus forged `Authentication-Results`, authserv identity, alignment, sender/domain, exact
  mailbox/recipient, and rule markers.
- One stored email emits one Signal and one import across repeated Email/Signal/queue processing.
- A resend with a new Email identity reaches Storage revision/duplicate logic.
- Matching rule stops ordinary Ticket ingress only as configured; nonmatching mail remains unchanged.
- Signal action validates payload, uses stable keys, records ordered action results, and retries
  safely.
- Source snapshot is sanitized and excludes raw EML, untrusted headers, tracking URLs, and secrets.
- Queue failure/retry and missing/deactivated profile produce explicit import state, not data loss.
- No PO, Item, receipt, Movement, or AI request is created.

## Documentation

- Email Knowledge for trusted source facts, supplier-order rules, and Ticket suppression.
- Signal Knowledge for the normalized event/action, audit, and retry.
- Storage Knowledge for source trace and import status.
- Queue/operations runbook and TODO/human-review updates.

## Done Criteria

- [x] A configured supplier confirmation creates exactly one deterministic Storage import proposal.
- [x] Explicit source trust and routing are visible and server-enforced.
- [x] Ordinary Email/Ticket behavior is unchanged outside configured rules.
- [x] Cross-module trace and retry/idempotency behavior are covered.
- [x] This handoff exposes no automatic PO, AI, receipt, or stock behavior by itself.
- [x] Focused Email, Signal, Storage, permission, and queue coverage is implemented on Dev.
- [x] Queue and human-review prerequisites are recorded honestly.
- [x] Install and verify the dedicated locked Dev inbound Email poller/worker and Supplier Orders runtime for the controlled shadow rollout.
- [x] Complete one real Email-to-Signal-to-Storage shadow trace with no Item, PO, receipt, Movement, or stock write.
- [x] Harden inbound polling with ordered raw headers and a persistent forward-only UID/`UIDVALIDITY` boundary; verify baseline, ordinary poll, and cron cycles on the live Dev mailbox without further historical ingest.
- [ ] Capture a second real message through ordinary polling after header/fairness hardening, preserve the protected fixture, calibrate trusted infrastructure, and complete named human review `HR-2026-08-04-003`.
