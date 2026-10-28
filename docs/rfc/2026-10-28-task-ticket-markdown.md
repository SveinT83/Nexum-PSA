# RFC: Task And Ticket Markdown

Status: Approved
Date: 2026-10-28
Owner: Codex / Svein Tore
Issue: #328 (Task/Ticket text formatting), audited before Svein's explicit "Fix alt og merge til main".

## Target Behavior

Technicians and portal users can write readable Markdown source in existing Task/Ticket description
and message fields. Line breaks, paragraphs, ordered/unordered lists, bold, emphasis, code, quotes
and safe links render consistently in Task detail/quick view, Ticket description/conversation,
the already-authorized Portal conversation, and Ticket-generated public/internal HTML email.
Plain text and Norwegian Unicode remain readable; edit forms and APIs retain the original source.
Rendering must not turn input into executable HTML, unsafe links or automatic remote-image requests.

## Current Evidence And Approved Scope

Task detail wraps escaped description in a paragraph; quick view uses escaped pre-wrap.
Ticket description/messages and Portal messages use escaped pre-wrap. The existing textarea fields
already persist source text, so no schema/content migration is required. No formatting helper or
editor currently explains Markdown. Existing API resources return raw description/body strings.
Ticket reply email currently inserts message_body through one scalar template variable in both
HTML/text; internal-note HTML uses escaped pre-wrap. EmailTemplateRenderer has existing branding,
custom layout, body-slot and subject/plain-text rules that must be preserved.

The user authorized all audited fixes and tested Main merge, which includes the concrete Markdown
request in #328. This RFC records that bounded approval and reversible technical defaults. It does
not approve a general rich-text/editor replacement, other module text fields, recipient changes,
external image loading, production rollout, stored-template rewrites or API mutation changes.

## Impact And Ownership

Shared presentation conversion lives in System/Actions with an anonymous shared Blade component.
Task/Ticket own their surfaces and source data. Email keeps template/layout/transport ownership;
one optional HTML-only variable override preserves ordinary subject/plain-text replacement.
Only the two Ticket jobs pass sanitized Markdown HTML, with their original source retained as
plain text. Existing Mail rich-HTML drafts and captured message content keep their own ownership.
No route, permission, audience, recipient, database, provider, queue or scheduler configuration changes.

Dependencies: installed league/commonmark and existing Bootstrap/Blade. No new package or asset
build. Bound nesting and handle image syntax as visible alt text; trusted attachments keep their
existing separate UI and download guards. Fail-safe fallback escapes source if conversion fails.
Shared dirty Ticket/Email work is preserved; Main extraction uses only exact hunks and new files.

## Feature Slice 1: Shared Markdown And Authorized Read Surfaces

Status: Done
Parent: this RFC / Issue #328
Owner: Codex

### Goal
Display requested formatting consistently without rewriting source or widening access.

### User-Visible Behavior
Task detail/quick view and Ticket/Portal conversation render Markdown and ordinary line breaks.

### Scope
One bounded shared renderer/component; Task description; Ticket description and message bodies;
Portal public conversation bodies. Existing content stays text in storage.

### Out Of Scope
Rich-text editor, remote images, changing which records/messages are visible, bulk source conversion.

### Data Touched
Renderer/component, existing Blade read surfaces, new unit/feature tests. No schema.

### Permissions
All current Task/Ticket/Portal queries and guards remain authoritative; renderer receives only
already-authorized text, and internal-note visibility does not change.

### Tests
Line breaks, list/bold/code/link rendering; plain/Unicode round-trip; hostile HTML/unsafe links/
remote-image absence; real Task/Ticket/Portal route access and hidden internal-note controls.

### Documentation
Task fields and Ticket communication/source Knowledge; RFC/ADR/TODO/human review.

### Done Criteria
All requested read surfaces use the component, source is unchanged, access controls persist,
and positive/negative renderer plus real-route feature checks pass on Dev and scoped Main.

## Feature Slice 2: Writing Guidance And Ticket Email Consistency

Status: Done
Parent: this RFC / Issue #328
Owner: Codex

### Goal
Make syntax discoverable and carry the same formatting into existing Ticket-generated HTML email.

### User-Visible Behavior
Existing description/message forms show brief English Markdown help. HTML replies/internal
notifications preserve formatting; subjects/plain-text MIME retain source and existing threading.

### Scope
Shared help component in operational Task/Ticket/template/quick-create/Portal forms; optional
HTML-only template variable override; two Ticket outbound jobs; no live send during verification.
A full-paragraph placeholder becomes a div wrapper when receiving block HTML, preserving its
attributes. Stored templates and all calls without overrides retain existing behavior.

### Out Of Scope
New template/recipient workflow, rewriting Mail HTML drafts, automatic sends or notification settings.

### Data Touched
Existing Blade forms, EmailTemplateRenderer, two Ticket jobs, tests and source/handbook docs.

### Permissions
No role, token, recipient, Portal visibility or sender grant. Existing publish, Mail and job guards
remain unchanged. Tests mock SMTP and do not alter live notification preferences.

### Tests
Rendered HTML vs raw subject/plain text, default/custom template stability without overrides,
reply/internal job delivery mocks and no extra send; existing Task/Ticket/Portal regressions.

### Documentation
Publish exact source Knowledge and read back actual BookStack with unchanged employee access.
Record Main vs production and HR-2026-10-28-TASK-TICKET-MARKDOWN.

### Done Criteria
Help is accurate; HTML/plain email assertions and affected workflow tests pass; exact Main merge,
Issue completion, source/handbook read-back and production manual-review status are recorded.

## Deployment And Rollback

No migration, stored-content backfill, source conversion, new dependency or asset build.
Refresh compiled Blade/cache normally after deployment. Reverting these presentation changes
restores plain rendering while retaining the exact original source. Production is separate.

## Human Review

HR-2026-10-28-TASK-TICKET-MARKDOWN remains Pending before production. Svein Tore should check
desktop/mobile long text, real Task/Ticket/Portal formatting, plain text/editing, and controlled
Ticket email display. Automated tests and explicitly requested Main merge never mark it Reviewed.

Literal-source clarification: calls using explicit HTML overrides substitute placeholders once.
Message data containing another placeholder is displayed literally in HTML/subject/plain MIME;
all calls without overrides retain their prior substitution behavior.


## Verification Before Main Merge

The pre-fix Task feature test failed at the missing strong-text output; it passes after the
presentation changes. Dev core rendering/routes 14 tests/46 assertions, workflows/API/Portal
44/325, Portal/Ticket email selection 5/22 and Email template/branding 7/61 all pass; batches
overlap. Isolated latest-Main candidate workflows/rendering 54/345 and Email/Ticket selection
12/76 passed, then final literal-source/template/Ticket regression 16/96 passed. The final
matching Dev literal-source batch also passed 16 tests/96 assertions before merge.

Five new PHP files pass Pint, and 10 scoped PHP files pass syntax checks. Main extraction uses
only recorded own hunks/new files/guide sections and preserves unrelated shared Dev work. No full
application suite or live SMTP send is claimed. Existing Task/Ticket APIs read back raw source.

Authoritative source Knowledge sections are published to actual BookStack pages 392/revision 9,
409/revision 35 and 421/revision 34. Independent full-body read-back confirms exactly the previous
article plus the owned section; all page access is unchanged. Task/Ticket chapters inherit the
existing book permissions; no permission expansion or employee OpenWebUI retrieval is claimed.
Native article lookup found no existing matching catalog entries, so none were invented or moved.

No migration, backfill, stored-template change, new dependency, asset build, recipient/grant or
production rollout occurred. HR-2026-10-28-TASK-TICKET-MARKDOWN remains Pending before production.


## Verified Main Completion

Both slices are complete and merged in PR #366, merge
65c5d249952ff357c364042f06befda3db5e72a7. Independent Git tree read-back exactly matches tested
candidate d66fa5d87059e7fc6c8667e0432ab8d39cca7665. #328 is closed completed; completion comment
6457398967 records scope and evidence. Its stale status:idea label was removed.
Final Dev and Main literal-source/template/Ticket batches each passed 16 tests/96 assertions.
Other verified batches are listed above; none are additive and no full suite/live send is claimed.
Main extraction validated 26 owned hunks and 30 scoped files; the shared Dev index/branch remained
unchanged. Production remains unchanged and the named human review remains Pending.


Final Main availability sections were independently read back in BookStack 392/revision 10,
409/revision 36 and 421/revision 35, exactly preserving the prior article plus the owned final
section. Existing page/chapter/book access remains unchanged. The public-safe website handoff
is editorial input only; it does not authorize publication or production claims.
