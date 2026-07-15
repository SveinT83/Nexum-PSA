# Human Review Register

This file is the persistent source of truth for human verification of substantial Nexum PSA
changes. It records what a person still needs to check, what failed, and what a named human reviewer
has explicitly approved.

## Working Rules

- Add one entry for every Level 2 or Level 3 change, completed Feature Slice, migration or data
  change, permission or integration change, cross-module update, substantial user-visible workflow,
  and broad merge or release candidate.
- Use a stable ID in the form `HR-YYYY-MM-DD-NNN` and update the same entry as review progresses.
- Valid statuses are `Pending`, `In Review`, `Rework Needed`, `Reviewed`, and `Superseded`.
- `[ ]` means outstanding and `[x]` means explicitly checked by a human. Record `N/A` with a reason
  when a check does not apply.
- Automated tests, code review, deployment, migration, or silence never changes the status to
  `Reviewed`.
- Only explicit confirmation from a named human reviewer may set `Reviewed`. Record the reviewer,
  date, environment, and any accepted deviations.
- Keep failed checks and follow-up notes on the same entry. Move the status to `Rework Needed` until
  the defect is fixed and rechecked.
- Before a merge, migration, deployment, or release, report every relevant entry that is not
  `Reviewed`.
- Never delete reviewed entries. Add newer entries above older entries and retain the history.

## Review Summary

| ID | Update | Status | Added | Reviewer | Reviewed |
| --- | --- | --- | --- | --- | --- |
| HR-2026-07-15-002 | Signal feed, rule builder, execution recovery, and retry | Pending | 2026-07-15 |  |  |
| HR-2026-07-15-001 | Main and Dev pre-merge user-interface review | In Review | 2026-07-15 | Svein Tore |  |

## Open Reviews

### HR-2026-07-15-002 - Signal Feed, Rule Builder, Execution Recovery, And Retry

Status: Pending
Added: 2026-07-15
Environment: Dev
Related: `docs/rfc/2026-07-15-signal-rule-builder-and-recovery.md`

Please review after the Dev verification is reported complete:

Automated Dev verification completed 2026-07-15: Signal 27 tests / 157 assertions; Email, Ticket,
and Intake regression 165 tests / 1105 assertions; Blade compilation and unauthenticated HTTP
smoke checks passed. These results do not replace the human checks below.

- [ ] `/tech/admin/system/signals` opens on the last 30 days; 7/30/90 days, custom dates, all
  history, search, filters, reset, sorting, and pagination behave as expected.
- [ ] `/tech/admin/system/signals/rules` shows priority and whether a successful rule stops
  lower-priority rules.
- [ ] `/tech/admin/system/signals/rules/create` uses compact condition groups and action rows;
  add/remove, all/any selection, contextual fields, action expansion, and drag ordering work.
- [ ] An existing legacy Signal rule opens in the builder and saves without losing its meaning.
- [ ] Rule Reference is readable in the right sidebar, while Advanced JSON stays collapsed and is
  used only after explicitly enabling `Save advanced JSON`.
- [ ] A rule with a failing action shows `Failed` and later actions as `Not Run`; another matching
  rule still executes.
- [ ] A successful rule with stop-processing enabled prevents a broader lower-priority rule.
- [ ] Signal detail shows each action's order, status, result, attempt number, and error.
- [ ] `Retry failed / unstarted` runs only outstanding actions. The warned `Run whole rule again`
  does not duplicate an already-created Ticket, Task, Sales follow-up, portal invitation, derived
  Signal, or webhook delivery.
- [ ] A user without `signal.action.execute` cannot see or call retry controls.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-15-001 - Main And Dev Pre-Merge User-Interface Review

**Status:** In Review  
**Added:** 2026-07-15  
**Reviewer:** Svein Tore  
**Review environment:** Local development (`nexum-psa.local`)  
**Scope:** Combined review candidate based on the latest `Main`, latest remote `Dev`, and the local
Dev worktree as inventoried on 2026-07-15. No merge or push was performed while preparing this
checklist.

**Completion condition:** A named human reviewer confirms every applicable check below. Any failed
check must be recorded under Review Notes and rechecked after correction.

#### Shared Application Shell

- [x] Login and welcome pages render correctly.
- [x] Technician navigation works on desktop and mobile.
- [x] Admin dashboard and Admin menu show the correct entries for the signed-in role.
- [x] PWA metadata, install behavior, online navigation, and offline fallback behave correctly.

#### Technician Ticket Workflow

- [x] Ticket creation works with the expected defaults and visibility controls.
- [x] Ticket detail supports replies, internal notes, attachments, and normal status actions.
- [x] CC suggestions stay hidden until the field receives focus or is clicked.
- [x] Selecting a CC suggestion inserts the correct email address without exposing an excessive
  suggestion list. Follow-up filtering and density work is accepted for GitHub issue #182.
- [x] Ticket costs handle ordinary stock, orderable out-of-stock items, and blocked non-orderable
  items correctly.
- [x] Ticket settings and Ticket rules render and save correctly.

#### Customer Portal

- [x] A new portal user can accept an invitation and choose a password.
- [x] An existing portal user can accept another valid invitation.
- [x] Expired, invalid, and already-used invitations show the correct result.
- [x] Portal dashboard, navigation, logout, and membership switching work.
- [x] Portal notifications can be opened, marked read, and configured.
- [x] Portal tickets can be listed, created, opened, replied to, and supplied with permitted
  attachments.
- [x] Published documents and Knowledge articles can be listed and opened.
- [x] Quotes can be listed, opened, accepted, and queried.
- [x] Contracts can be listed, opened, and accepted.
- [x] Published orders can be listed and opened.
- [x] A portal user cannot access another customer, site, membership, or unpublished record.

#### Booking

- [x] Admin Booking list renders correctly.
- [x] Booking services can be created and edited with technician, location, duration, availability,
  notice, instructions, active state, and abuse-protection settings.
- [x] Public Booking list and service page show valid availability.
- [x] A visitor can choose a date and slot, submit contact details, and reach the thank-you page.
- [x] Empty availability and validation errors are presented honestly.
- [x] Admin request detail supports confirmation and rejection with the expected Calendar handoff.
- [x] Booking create/edit places Back in the shared page header. Accepted for GitHub issue #184.
- [x] Booking services support a configurable daily opening window, for example 10:00-15:00.
  Accepted for GitHub issue #184.
- [x] Booking availability follows company working hours by default and can instead follow the
  selected technician's working hours. Accepted for GitHub issue #184.
- [x] Booking supports a fixed technician, automatic assignment from available technicians without
  exposing the technician to the customer, and optional customer technician selection. Accepted
  for GitHub issue #184.
- [x] Honeypot protection is explained in plain language or moved to an advanced section. Accepted
  for GitHub issue #184.

#### Intake

- [x] Admin Intake list and submission list render correctly.
- [x] Forms can be created and edited with supported fields, validation, layout, choices,
  conditional visibility, file uploads, ordering, and active state.
- [x] A public form can be submitted and reaches the correct thank-you page.
- [x] Conditional fields, required fields, attachments, and abuse protection work.
- [x] Admin submission detail supports review, attachment download, and permitted Sales routing.

#### Data Exchange

- [x] Data Exchange profiles can be listed, created, and edited.
- [x] Direction, format, source/target, field mapping, relations, and filters save correctly.
- [x] Export can run and the generated file can be downloaded.
- [x] Import supports dry-run preview before an explicit commit.
- [x] Run history and run detail show accurate results and errors.
- [x] Schedule and delivery-target forms render and save correctly.

#### Signal And Rules

- [ ] Signal feed and Signal rule list render correctly.
- [ ] Signal rules can be created and edited using structured conditions and actions.
- [ ] Advanced JSON remains consistent with the structured rule form.
- [ ] Signal settings save AI enablement, confidence, source, type, routing, and prompt values.
- [ ] Email Rules and Ticket Rules can emit Signal records without creating loops.

**Agreed Signal direction:** Svein Tore confirmed on 2026-07-15 that ordinary emails and tickets
must not create Signals. Email Rules and Ticket Rules may hand off to Signal only when an admin
explicitly configures an `Emit Signal` action. Email remains responsible for email-local processing,
Ticket remains responsible for ticket-local classification and routing, and Signal handles
normalized events and cross-module automation.

**Signal UI requirements approved for implementation on 2026-07-15:**

- Signal Feed defaults to the last 30 days, with an explicit way to search older/all history or use
  a different date range.
- Signal Feed supports sorting rather than always forcing the latest-first order.
- Signal Rule create/edit uses a builder comparable to the Intake form builder. Actions are compact
  rows/cards, a `+` control adds another action, and only fields relevant to the selected action are
  shown.
- Conditions use the same builder pattern with compact rows/cards, `+ Add condition`, removal, and
  field/operator/value controls that adapt to the selected condition type.
- A rule-level match selector supports `All conditions must match` and `At least one condition must
  match`. `All conditions must match` is the default.
- Advanced conditions support `+ Add group`. Each condition group can independently use `All` or
  `At least one`, allowing expressions such as `source is Email AND (type is backup failure OR type
  is security alert)` without making the default builder complex.
- All matching rules execute in priority order by default. A rule can enable `Stop processing more
  rules after this rule` so a specific rule can prevent broader fallback rules from duplicating its
  work.
- Action order remains explicit because Signal actions execute in sequence.
- If an action fails, the remaining actions in that rule stop and the execution records the error.
  Other matching rules may still run. `Stop processing more rules` applies only when its rule
  completes successfully.
- Rule Reference moves from the main form body to the right sidebar.

#### Other Changed User Surfaces

- [x] Warroom My Day renders the selected date, metrics, Calendar items, tickets, tasks, queues, and
  working links.
- [x] Warroom/dashboard shows Storage items with `Should order` status when such items exist.
  Accepted for GitHub issue #183.
- [x] Client detail can start creation of a correctly associated Contact.
- [x] Contact create/edit handles transactional SMS consent.
- [x] Contact settings can control the default for automatic portal invitations.
- [x] Contact create lets the technician override the automatic portal-invitation default for the
  individual Contact. Accepted for GitHub issue #185.
- [x] Notification channel list and SMS settings/test flow behave correctly, including consent
  blocking.
- [x] Marketing campaign create/edit/show supports stop/repeat completion behavior and WordPress
  content context.
- [x] Sales Leads shows the expected marketing engagement information.
- [x] Economy order list, detail, export, and portal-visibility controls work.
- [x] Storage item create/edit/show handles orderable stock-shortage settings correctly.
- [x] Public and portal quote views render and preserve acceptance behavior.
- [x] Contract creation, public contract view, portal invitation option, and portal contract view
  work.
- [x] Technician Documentation view correctly controls portal publication.
- [x] User profile, role, and permission pages enforce the expected access.
- [x] Every new Admin menu destination is hidden or denied for roles without permission.

#### Review Result

**Review notes:** Review started by Svein Tore on 2026-07-15. Intake was reported as looking very
good. Svein Tore then explicitly approved every checklist item not mentioned in the review feedback.
The current CC panel was reviewed from Ticket detail. Signal and Rules must receive a separate,
careful review later and remain entirely unchecked. The other reported follow-ups were explicitly
accepted as later GitHub work and no longer block this human review.  
**Failed checks:** Ticket CC suggestions consume too much space, include global Contacts that should
not be offered, and repeat the Contact already selected on the Ticket. Booking create keeps Back at
the bottom instead of in the page header. Booking also lacks service-specific opening hours,
company-hours versus technician-hours behavior, automatic selection from available technicians,
and optional customer technician choice. The raw `Honeypot field` setting is too technical for the
normal Booking form and needs plain-language help or placement in an advanced section. Storage
`Should order` demand is not surfaced on the dashboard/Warroom. Contact create lacks a per-Contact
override for automatic portal invitation.  
**Accepted deviations:** Svein Tore accepted the non-Signal follow-up work for later implementation
through GitHub issues #182 (Ticket CC suggestions), #183 (Storage `Should order` in Warroom), #184
(Booking hours, technician routing, Back placement, and honeypot explanation), and #185 (Contact
portal-invitation override). These issues do not block the current merge review.  
**Final human confirmation:** Partial confirmation provided on 2026-07-15 for every checklist item
not explicitly left open above. Full confirmation is not yet provided.

## Reviewed History

No completed human reviews have been recorded yet.
