# Feature Slice: Email Mail Smart Inbox Reader-First Polish

Status: Done
Date: 2026-08-15
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Keep the selected email and conversation primary while making Smart Inbox review controls honest about
current availability and stable across unrelated local projection or state maintenance.

## User-Visible Behavior

- Smart Inbox results remain after the selected email body and conversation. The follow-up desktop
  polish keeps one compact trigger above the reader, while available results start collapsed whenever
  that Mail selection is opened or revisited.
- A technician explicitly expands the section to review current suggestions; the disclosure remains
  keyboard and screen-reader operable.
- Terminal stale, dismissed, or revoked suggestions remain in durable audit history but do not clutter
  the ordinary selected-message reader. Applied results remain visible as useful history.
- An Apply, batch, correction, or rule-prefill control is shown only when the recorded agent and the
  current user, mailbox, target, and exact action remain eligible. Direct forged actions still fail at
  the server boundary.
- When Smart Inbox is unavailable and has nothing usable to show, its reader card disappears instead
  of presenting an action that is guaranteed to fail.
- Non-content maintenance such as a derived search projection or an unrelated model timestamp does not
  claim that the conversation changed. Real message/content membership changes still stale old review
  suggestions.

## Scope

- Keep the result region after the mail reader and default-collapsed. The later desktop workspace
  polish separates its accessible trigger above the reader without duplicating the scoped Livewire
  query/eligibility owner.
- Re-evaluate read and write capability for presentation without weakening action-time authorization.
- Filter ordinary reader presentation to current, useful suggestions while preserving stored rows,
  events, API evidence, and authorized direct audit access.
- Make the current conversation fingerprint content/source based rather than dependent on mutable
  Eloquent bookkeeping timestamps, while evaluating old suggestions with their recorded schema.
- Add focused Livewire, capability, authorization, and fingerprint regressions.

## Out Of Scope

- Automatically applying suggestions, external replies, background analysis, or expanding AI data
  egress.
- Deleting durable suggestion/event history or changing API history visibility without a separately
  reviewed contract.
- Repairing the `received_at` schema/data regression; that rework remains part of the decoded-subject
  search slice and `HR-2026-08-15-004`.
- Attachment access/recovery, provider mutation, Taxonomy changes, Task semantics, or AI-agent policy
  administration.

## Data And Permissions

No new permission is introduced. Forward migration `121200` adds a nullable fingerprint-schema field
to existing suggestion rows so legacy schema-v1 evidence and current schema-v2 evidence can be
evaluated honestly. Existing suggestion/event rows remain authoritative audit evidence.
Presentation-time eligibility is defense in depth; existing Mailbox View/Organize checks,
recorded-agent provenance, named scopes, target compare-and-set checks, and action-time authorization
remain mandatory.

## Verification

- Suggestions are collapsed on initial mount, selection change, and a fresh return to the message;
  the selected mail body remains above the disclosure.
- The controlled trigger/result region remains keyboard and screen-reader operable without nested or
  unsynchronized interactive controls.
- A read-capable but write-disabled agent may still offer Analyze, while write suggestions and batch or
  rule actions that cannot run are absent.
- Revoked account access, inactive account/agent, missing exact scope, deleted target, and stale source
  hide unavailable controls; forged calls remain rejected.
- Unrelated `updated_at`, state, or derived search-projection changes do not stale a suggestion, while
  changed subject/body/participants/attachments or conversation membership do.
- No raw provider/model payload or inaccessible account identity is exposed through empty/error states.

## Dev Verification And Deployment

- The Smart Inbox reader/capability regressions pass 21 tests / 306 assertions.
- The receipt-timestamp repair plus adjacent Smart Inbox regressions pass 36 tests / 408 assertions;
  the earlier combined repair/reader verification passed 47 tests / 578 assertions.
- The complete Email test directory passes 347 tests / 3,030 assertions.
- Follow-up desktop workspace verification passes 20 / 337 focused and 349 / 3,066 across the
  complete Email directory; its placement review is tracked separately by `HR-2026-08-15-007`.
- Migration `121200` ran on Dev in batch 98. The exact repair reactivated five suggestions falsely
  staled by the old receipt-timestamp clause, while later or mismatched stale evidence remained stale.
- The completed attachment recovery adds exact attachment metadata to 16 messages. Schema-v2
  fingerprints intentionally treat that recovered content evidence as a real source change; this is
  distinct from the unrelated bookkeeping writes that v2 ignores.
- Deploy after `121200`, clear caches with `umask 0002; HOME=/tmp php artisan optimize:clear`, rebuild
  compiled views with `umask 0002; HOME=/tmp php artisan view:cache`, restart long-lived ordinary and
  `email` workers, and push the Email Knowledge sync with
  `php artisan knowledge:sync-docs --module=Email --push`.

## Documentation

Update the Email Knowledge article, Email module README, `docs/TODO.md`, and `docs/human-review.md`.
Human review is tracked as `HR-2026-08-15-005` and remains Pending until a named reviewer completes it.

## Done Criteria

- [x] Smart results remain after the email reader and default-collapsed, with one accessible trigger
  above the reader after the documented follow-up polish.
- [x] Terminal and currently unavailable pending actions disappear from the ordinary reader while
  applied history and durable audit evidence remain intact.
- [x] Presentation and forged/direct actions retain independent current authorization checks.
- [x] Current fingerprints ignore unrelated bookkeeping timestamps while preserving content and
  membership staleness, and legacy evidence uses its recorded schema.
- [x] Focused regressions, migration repair verification, documentation, and Pending human review
  entries are recorded.
