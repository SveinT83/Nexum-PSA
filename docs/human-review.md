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
| HR-2026-08-10-003 | Simplified automatic Storage Supplier Order AI and profile bootstrap | In Review | 2026-08-10 | Svein Tore |  |
| HR-2026-08-10-002 | Storage incoming Purchase Order quantity visibility | Pending | 2026-08-10 |  |  |
| HR-2026-08-10-001 | Unified Storage Supplier Orders list | Pending | 2026-08-10 |  |  |
| HR-2026-08-04-003 | Supplier email-to-Purchase Order automation implementation | Pending | 2026-08-04 |  |  |
| HR-2026-08-04-002 | Sortable Storage Inventory queues, Admin lists, and detail history | Pending | 2026-08-04 |  |  |
| HR-2026-08-04-001 | Supplier purchase orders, shipping, and goods receiving | Pending | 2026-08-04 |  |  |
| HR-2026-07-29-013 | Production Ticket external-message API route | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-012 | AI privacy governance and coordinator worklog API | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-011 | Quote billing cadence and customer copy | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-010 | Sales opportunity lost and reopen workflow | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-009 | Documentation template selection | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-008 | Calendar ownership rollout tests and Knowledge | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-007 | Calendar mobile readability and dense month drill-down | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-006 | Calendar ownership filters and Only mine | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-005 | Calendar non-personal type indicators | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-004 | Calendar owner badges and accessible color identity | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-003 | Calendar ownership view metadata and private single-event API | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-002 | Ticket API portal publication and idempotent customer completion | Pending | 2026-07-29 |  |  |
| HR-2026-07-29-001 | Published default for manually created client Tickets | Pending | 2026-07-29 |  |  |
| HR-2026-07-28-005 | Ticket Internal note solution toggle | Pending | 2026-07-28 |  |  |
| HR-2026-07-28-004 | Client Summary layout and Notes autosave | Pending | 2026-07-28 |  |  |
| HR-2026-07-28-003 | Client workspace Tickets tab | Pending | 2026-07-28 |  |  |
| HR-2026-07-28-002 | Contact portal invitation create override | Pending | 2026-07-28 |  |  |
| HR-2026-07-28-001 | Booking hours and technician routing | Pending | 2026-07-28 |  |  |
| HR-2026-07-27-003 | Warroom Storage Should order warning | Reviewed | 2026-07-27 | Svein Tore | 2026-07-28 |
| HR-2026-07-27-002 | Ticket reply CC suggestion filtering and compact panel | Reviewed | 2026-07-27 | Svein Tore | 2026-07-28 |
| HR-2026-07-27-001 | AI model execution contract and usage ledger | Pending | 2026-07-27 |  |  |
| HR-2026-07-24-001 | Web Push channel and internal-user device foundation | In Review | 2026-07-24 | Svein Tore |  |
| HR-2026-07-22-001 | CloudFactory versioned legal documents and portal licence ordering | Pending | 2026-07-22 |  |  |
| HR-2026-07-21-001 | Ticket Storage reservation release and quantity-zero removal | Pending | 2026-07-21 |  |  |
| HR-2026-07-20-001 | CloudFactory two-way Client, catalogue, licence, contract, and Economy integration | Pending | 2026-07-20 |  |  |
| HR-2026-07-17-001 | Ticket Workflow v3 conditional actions, escalation, review, and commercial approval | In Review | 2026-07-17 | Svein Tore |  |
| HR-2026-07-16-001 | Automatic release metadata and Admin GitHub version status | Pending | 2026-07-16 |  |  |
| HR-2026-07-15-002 | Signal feed, rule builder, execution recovery, and retry | Pending | 2026-07-15 |  |  |
| HR-2026-07-15-001 | Main and Dev pre-merge user-interface review | In Review | 2026-07-15 | Svein Tore |  |

## Reviewed History

### HR-2026-07-27-003 - Warroom Storage Should Order Warning

Status: Reviewed
Added: 2026-07-27
Environment: Dev
Related: GitHub issue #183, `docs/rfc/2026-07-08-storage-orderable-over-reservations.md`, and
follow-up from `HR-2026-07-15-001`

Scope: Storage owns one shared `Should order` query for the inventory index, Storage quick-stat
count, and Warroom count. Warroom shows a compact warning only when the count is above zero and the
signed-in user has `storage.view`. The warning links directly to the existing reorder-filtered
Storage index and is omitted for zero state and unauthorized users.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Warroom --no-interaction`. No migration, permission seed, queue restart,
scheduler change, or frontend build is required.

Risks: Warroom must not disclose inventory pressure to users without Storage access; the warning
must not create permanent dashboard noise at zero; and the count must stay aligned with the actual
Storage `Should order` result when reorder rules evolve.

Automated verification: the complete Warroom dashboard and Storage feature suites pass with 23 tests
and 248 assertions. The focused positive, zero, unauthorized, and shared-query regression run passes
with 4 tests and 19 assertions. PHP syntax, Git diff checks, and Blade cache compilation pass.

Human checks:

- [x] As a user with `warroom.view` and `storage.view`, create or identify one manual, empty-stock,
  over-reserved, or reorder-point item and confirm the compact warning and count appear in Warroom.
- [x] Open `Open Should order` and confirm the filtered Storage list contains the same items as the
  displayed count.
- [x] Resolve every reorder condition and confirm the Warroom warning disappears instead of showing
  a permanent zero state.
- [x] As a user with `warroom.view` but without `storage.view`, confirm no Storage reorder count or
  link is visible.
- [x] Check desktop and mobile widths and confirm the alert stays compact and does not crowd the
  pulse cards or main operations grid.

Reviewer: Svein Tore
Reviewed date: 2026-07-28
Result / notes: Approved by Svein Tore in the Codex task after reviewing the completed work.

### HR-2026-07-27-002 - Ticket Reply CC Suggestion Filtering And Compact Panel

Status: Reviewed
Added: 2026-07-27
Environment: Dev
Related: GitHub issue #182 and follow-up from `HR-2026-07-15-001`

Scope: Ticket reply CC suggestions are limited to other active contacts connected to the Ticket
client. Global Contacts and the Ticket contact are excluded by the server. The currently selected
reply recipient is filtered in the browser and re-evaluated when the recipient changes. The compact,
scrollable Bootstrap list stays hidden until the CC field is focused or clicked, and selecting an
entry preserves manually entered addresses without adding duplicates.

Deployment actions: deploy the code and run `php artisan optimize:clear` plus `php artisan
knowledge:sync-docs --module=Ticket --no-interaction`. No migration, permission seed, queue restart,
scheduler change, or frontend build is required.

Risks: a contact linked through the wrong Client/Site boundary must not be suggested; the selected
reply recipient must not also receive CC; focus handling must not make the compact list impossible
to use with keyboard or pointer; and manual CC addresses must remain intact.

Automated verification: the complete Ticket feature suite passes with 111 tests and 792 assertions,
including the focused CC regression with 11 assertions. PHP syntax, Git diff checks, and Blade cache
compilation pass. Browser verification on Dev remains open because the in-app browser rejects the
known self-signed `nexum-psa.local` certificate with `ERR_CERT_AUTHORITY_INVALID`.

Human checks:

- [x] Open a Published client Ticket with its primary Contact plus at least two other active client
  Contacts, focus or click CC, and confirm a compact scrollable list appears.
- [x] Confirm the Ticket Contact, current reply recipient, global Contacts, inactive Contacts, and
  Contacts from another Client are absent.
- [x] Change the reply recipient and confirm the newly selected recipient disappears while the
  previous non-primary recipient becomes eligible again.
- [x] Select a suggestion and confirm its email is appended without removing manually entered CC
  addresses; select it again and confirm no duplicate is added.
- [x] Verify pointer and keyboard focus can reach a suggestion and the list closes cleanly after
  selection or when focus leaves the CC controls.
- [x] Check the compact list at desktop and mobile widths and confirm it does not expand the Ticket
  composer excessively.

Reviewer: Svein Tore
Reviewed date: 2026-07-28
Result / notes: Approved by Svein Tore in the Codex task after reviewing the completed work.

## Open Reviews

### HR-2026-08-10-003 - Simplified Automatic Storage Supplier Order AI And Profile Bootstrap

Status: In Review
Added: 2026-08-10
Environment: Dev
Related: `docs/rfc/2026-08-10-simplified-storage-supplier-order-ai.md`,
`docs/rfc/2026-08-11-operational-supplier-order-automation-setup.md`,
`docs/rfc/2026-08-11-automatic-ai-supplier-profile-bootstrap.md`,
`docs/adr/2026-08-10-managed-domain-ai-and-system-actor.md`, the three linked 2026-08-10 Feature
Slices, `docs/feature-slices/2026-08-11-storage-operational-supplier-order-setup.md`, and
`docs/feature-slices/2026-08-11-storage-automatic-ai-profile-bootstrap.md`.

Scope: Supplier Order Automation selects an existing Storage-domain agent and enables AI fallback
without manual workload, capability-empty-agent, or human automation-user setup. Integration
creates a capability-isolated managed structured workload. User Management provides one protected,
non-login Nexum system actor with exact direct permissions. The ordinary form now exposes only
plain-language order handling, warehouse, unknown-supplier/Item behavior, AI use, one Storage agent,
business limits, and notifications. Nexum derives the outcome and replaces technical browser values
with a complete safe preset. Storage keeps every existing source, evidence, identity, arithmetic,
warehouse, Item, finalization, idempotency, lifecycle, and no-receipt guard.

A trusted valid email with no matching profile may now create or reuse one active Supplier, activate
a Supplier-linked immutable profile from a protected machine-verified fixture, create distinct
active/orderable Items within the configured cap, and create one editable `ordered` Purchase Order.
Later matching messages use the deterministic profile. Retry and close first-message paths reuse the
same identities. Genuine trust, evidence, candidate, ambiguity, duplicate, provider, or business-
limit failures remain exceptions. No email path receives goods or changes stock.

Deployment actions: the original two migrations already ran in Dev batch 64. This follow-up needs
code, optimized-cache clearing, and Storage Knowledge sync only. No migration, permission seeder,
frontend build, scheduler change, or persistent worker restart is required; the isolated Supplier
Orders runner starts a fresh process each minute. Dev policy revision 11 is current with automatic
profile-or-AI handling, fallback, active Supplier/Item creation, max 250 new Items, one verified
bootstrap sample, warehouse 2, and standard `gpt-5.5`. Existing pinned imports are unchanged.

Risks: broad agent capabilities could leak into extraction; domain-scoped external AI could send
broader data than intended; the system actor could become login-capable or human-editable; or a
managed workload could remain active after policy changes. Hidden or forged learning, consensus,
cost, timeout, retry, or JSON values could also make the simple form unsafe or unusable. Capability
isolation, forced privacy relay/pseudonymized profile, server-owned defaults, auth/user-management
guards, stable keys, deactivation, and tests are required mitigations.

Automatic profile bootstrap also risks noisy master data, an overly broad scope, retry duplicates,
or equal-priority profiles. Mitigations are immutable trusted source, exact evidence, server-owned
account/mailbox/recipient/sender/authenticated-domain scope, constrained declarative definitions,
protected replay and canonical reproduction, Supplier/SKU identity, a Supplier-row lock with matcher
recheck, pinned-policy verification, retry idempotency, and Supplier/Item/order caps.

Commercial numbers that resemble personal data are additionally replaced with opaque request-local
tokens and restored only in memory after the response; the provider never receives the originals
and mappings are not persisted.

Automated verification: the earlier managed-agent/actor verification remains valid. Focused
Integration, UI, policy, boundary, pipeline, and AI automation passes 94 tests / 971 assertions.
After Pint corrected three style issues, the final Integration/AI/policy rerun passes 57 / 579. The
complete Storage suite passes 257 / 2,775 with the existing opt-in MariaDB contract skipped. Blade
cache compilation and Storage Knowledge sync pass. A controlled real-provider transaction on the
selected agent using standard `gpt-5.5` completed in about 21 seconds and proved valid evidence,
valid Storage-owned profile candidate, active Supplier/profile/Item, and one ordered Purchase Order.
It rolled back with zero persistent receipt, Movement, Stock Unit, or inventory delta. Policy save
then created revision 11 with zero import, Item, Purchase Order, receipt, Movement, or Stock Unit
delta. The in-app browser cannot resolve the local `.local` hostname, so named human visual and real-
email review remain required. Automated tests never complete this review.
The isolated cron entries are present, scheduler and worker heartbeats are healthy at about 21
seconds old, queue latency is zero, and there are no stale processing rows, open operational alerts,
failed alert deliveries, or failed queue jobs. Operational state remains warning only because nine
existing calibration imports are intentionally waiting in `needs_attention` for separate review.

Human checks:

- [ ] Open **Admin > Storage > Supplier Order Automation** and confirm the page shows only Order
  handling, warehouse, unknown suppliers/Items, AI assistance, order limits, and notifications.
- [ ] Confirm there is no Default outcome or Advanced settings control and no learning, consensus,
  workload, confidence, timeout, token, cost, retry, circuit-breaker, retention, or JSON field.
- [ ] Confirm **Register automatically after supplier profile or AI verification**, fallback, the
  existing Storage agent, and the intended warehouse/supplier/Item choices remain selected. Save and
  confirm the page reports ready without visiting Integration Privacy/Workloads.
- [ ] Confirm User Management does not list a human-editable or login-capable automation account,
  while an automated import audit names **Nexum Supplier Order Automation**.
- [ ] Run a synthetic supplier-order fallback and verify access audit identifies the selected agent,
  provider/model, managed workload, privacy relay, and pseudonymized profile without raw email.
- [ ] Confirm the selected agent's ordinary chat tools, data sources, roles, API scopes, and action
  setting are unavailable to the structured extraction call.
- [ ] Forward one controlled confirmation from a trusted supplier with **no existing profile**.
  Confirm AI is fallback after profile matching, exactly one active Supplier is present, one
  Supplier-linked active profile/version and protected bootstrap fixture exist, distinct Items and
  Supplier mappings are active/orderable, and one editable `ordered` Purchase Order includes its
  Email Copy. Confirm there is no receipt, Movement, Stock Unit, on-hand change, or supplier-order
  submission.
- [ ] Forward a later matching controlled confirmation and confirm it reuses the same Supplier,
  profile/version, and Item mappings with deterministic extraction and no second profile or order
  identity conflict.
- [ ] If practical, run one invalid/untrusted controlled sample and confirm it stops with an
  explainable exception before Item or Purchase Order writes.
- [ ] Change agent and turn AI off. Confirm the old managed workload cannot execute and deterministic
  processing remains available.
- [ ] Check the policy at desktop and narrow/mobile widths, including labels, help text, keyboard
  access, validation messages, and the Change history table.

Reviewer: Svein Tore
Reviewed date:
Result / notes: Svein Tore reported during review on 2026-08-11 that the remaining Advanced settings
panel was too complex and could require an unexplained second workload. The form and server contract
have been reworked. He then explicitly requested no routine manual approvals and automatic creation
of a missing Supplier profile. The implementation and machine/provider checks pass; awaiting his
explicit recheck of the revised Dev page and controlled real-email bootstrap/reuse behavior.

### HR-2026-08-10-002 - Storage Incoming Purchase Quantity Visibility

Status: Pending
Added: 2026-08-10
Environment: Dev
Related: `docs/rfc/2026-08-10-storage-incoming-purchase-quantity-visibility.md`

Scope: The Inventory Items queue now exposes a sortable **Incoming** quantity calculated from
positive outstanding quantities on non-deleted Purchase Orders in `ordered` or
`partially_received`. Received and cancelled line quantities are subtracted. Draft, received,
closed, cancelled, deleted, and fully balanced orders do not count. A row with positive incoming
quantity shows **On order** ahead of the existing **Should order** state. Incoming remains planning
information and does not increase on-hand or available stock before an authorized goods receipt.
Inventory users see only the aggregate quantity; Purchase Order identity, cost, tracking, receipt
history, and actions retain their existing permissions and pages.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, seeder,
backfill, queue restart, scheduler change, permission change, API update, external connector setup,
or frontend build is required.

Risks: historical or inactive orders could inflate incoming quantity; receipt/cancellation progress
could be counted twice; an incoming number could be mistaken for usable stock; or a Storage-only
user could gain Purchase Order details. The query counts only positive line balances on active
placed-order statuses, the table keeps On-hand, Available, and Incoming separate, receipt posting
remains unchanged, and only the aggregate is rendered on Inventory.

Automated verification: the focused Storage module suite proves active ordered and partially
received aggregation, received/cancelled subtraction, exclusion of draft/received/closed/cancelled
and soft-deleted orders, aggregate-only permission behavior, the **On order** badge, and ascending
and descending Incoming sorting with current view preservation. It passes 23 tests / 360 assertions.
The full Storage suite passes 251 tests / 2,600 assertions with the dedicated MariaDB identity
contract skipped because its opt-in credentials are not configured. Pint and Blade compilation
pass. A read-only Dev-data check confirms item `IMP-18-1324745-5BA90BDA` projects on-hand 0,
reserved 0, available 0, incoming 1, and display status `on_order`. Automated checks do not complete
this review.

Human checks:

- [ ] Open **Storage > Inventory** in the default reorder-focused view and confirm
  `IMP-18-1324745-5BA90BDA` shows **Incoming = 1** and status **On order**, not only **Should order**.
- [ ] Confirm On-hand and Available remain 0 before receipt, and that Inventory does not imply the
  incoming unit can be picked or used.
- [ ] Open the authorized Supplier Order/Purchase Order view and confirm the related active order has
  one outstanding unit. Repeat as a Storage-only user and confirm Inventory exposes no order number,
  cost, tracking, receipt history, or Purchase Order action.
- [ ] In a controlled test order, partially receive and separately cancel line quantity. Confirm
  Incoming decreases by exactly those quantities and On-hand increases only for accepted receipt
  quantity.
- [ ] Confirm draft, received, closed, cancelled, deleted, and fully balanced orders contribute zero
  Incoming quantity.
- [ ] Sort Incoming ascending and descending with search and filters active. Confirm the current view
  is preserved and equal values remain stable.
- [ ] Review the Inventory table at desktop and narrow/mobile widths, including horizontal scrolling,
  keyboard focus, readable badges, and alignment of numeric columns.

Reviewer:
Reviewed date:
Result / notes: Awaiting explicit named human review on Dev.

### HR-2026-08-10-001 - Unified Storage Supplier Orders List

Status: Pending
Added: 2026-08-10
Environment: Dev
Related: `docs/rfc/2026-08-10-storage-unified-supplier-order-list.md`

Scope: Storage now presents Supplier Order Imports, canonical Purchase Orders, and receiving work in
one permission-aware **Supplier Orders** list. An unlinked import is one incoming row. Once an import
links to a visible Purchase Order, the import remains available through an audit link inside the
single canonical order row instead of becoming a duplicate work item. Open receiving progress,
control-slip access, and the authorized Receive action are shown on that same row. Import detail,
Purchase Order detail, receiving forms, receipt posting, inventory effects, APIs, routes, and
permissions keep their existing boundaries. Manual and email-created orders use the same canonical
detail structure. An email-created order adds only a permission-protected **Email Copy** card with
sanitized header fields and body as the final main-content section after Shipments and Receipt
History. Technical import evidence remains on the import audit page, while trusted authentication
evidence remains internal and is not presented on either detail page.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and sync the updated Storage
Knowledge sources when this change is released. No migration, seeder, queue restart, scheduler
change, external connector setup, or frontend build is required.

Risks: linked imports could appear twice; an import-only or receiving-only user could gain Purchase
Order visibility; mixed-row search, filters, sorts, or pagination could omit work; and a Receive
button could imply that an import changes stock. The shared query deduplicates only against Purchase
Orders visible in the same list, controllers pass explicit permission scopes, mutation routes remain
unchanged, and the UI states that imports never update stock. The email card could expose technical
governance data or make an email order look like a separate workflow; the canonical order now shows
only the sanitized email copy, under the existing import-view permission.

Automated verification: focused coverage proves one-row deduplication, full-workflow, import-only,
and receiving-only visibility, legacy index-route rendering, receiving filtering/action visibility,
permission denial, identical manual/email order structure, permission-protected email content and
bottom placement after operational sections, absence of SPF, DKIM, DMARC, and authentication status
from both detail pages, plus absence of policy, profile, and AI controls from the operational order.
The focused import UI suite passes 7 tests / 130 assertions. The full Storage suite passes 250 tests / 2,588
assertions with the dedicated MariaDB contract skipped because its opt-in credentials are not
configured. Blade compilation, Pint, and diff checks pass. Automated tests do not complete this
review.

Human checks:

- [ ] With Purchase Order, import, and receive permissions, open **Storage > Supplier Orders** and
  confirm the sidebar contains one Supplier Orders entry rather than three separate list entries.
- [ ] Confirm one unresolved import appears as an incoming row. Open it and verify the full safe
  import audit/retry workflow still works.
- [ ] Confirm an import linked to a Purchase Order produces one order row, with one Import audit link
  and no duplicate incoming row.
- [ ] Open one manual order and one email-created or email-confirmed order. Confirm Order Details,
  Order Lines, Shipments/tracking, Receipt History, and actions have the same layout. Confirm only the
  email order adds an Email Copy with subject, sender, recipients, received time, and readable body,
  and that this card is the final main-content section after Shipments and Receipt History.
- [ ] Confirm neither the operational email order nor the supplier-import detail shows Trusted
  Authentication, SPF, DKIM, DMARC, or authentication alignment. Confirm import profile, policy,
  extraction, and repair details remain available to an authorized reviewer on the import audit page.
- [ ] Use All, Needs attention, Incoming, Purchase Orders, Receiving, and Completed plus search,
  filters, sorting, and pagination. Confirm every visible row remains reachable and the current view
  is preserved by sort links.
- [ ] For an open partial order, confirm ordered/received/outstanding progress, control slip, and
  Receive are in the same row. Post a controlled receipt and verify stock changes only after form
  confirmation.
- [ ] Repeat as a Purchase Order view-only user, an import-only user, and a receiving-only user.
  Confirm each sees only existing permission scope and no unauthorized links or actions.
- [ ] Check the unified table and filter controls at desktop and mobile widths, including keyboard
  focus, horizontal scrolling, badges, and next-step actions.

Reviewer:
Reviewed date:
Result / notes: Awaiting explicit named human review on Dev.

### HR-2026-08-04-003 - Supplier Email To Purchase Order Automation

Status: Pending
Added: 2026-08-04
Environment: Dev, followed by a controlled real-email shadow rollout
Related: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`,
`docs/adr/2026-08-04-supplier-email-purchase-import-ownership-and-ai-decision-boundary.md`, and
the eight linked 2026-08-04 Feature Slices plus
`docs/feature-slices/2026-08-07-storage-manual-email-supplier-order-identity-reconciliation.md`

Scope: Implemented Level 3 Email, Signal, Storage, Documentation, Integration, Notification,
permission, database, queue, scheduler, and Knowledge update for supplier order-confirmation
automation. Email retains the durable source and trusted receiving facts, an explicitly configured
pre-routing Email rule emits one minimized Signal and suppresses ordinary Ticket ingress for only
that match, Signal records and retries one stable Storage action, and Storage owns the immutable
import, policy/profile versions, deterministic extraction, line resolution, hard gates, exception
workflow, and final Purchase Order action. Documentation owns guarded Supplier bootstrap and
Integration owns the governed, non-writing, tool-free AI execution boundary.

The approved 2026-08-07 follow-up brings manual and email-created orders under one exact normalized
identity: supplier plus supplier external order number. The same number at another supplier remains
distinct, and a blank supplier number has no automatic match. A trusted, materially matching
confirmation attaches immutable source provenance to an existing active manual PO instead of
creating another, without replacing its internal number, lines, lifecycle, receipt history, or stock.
A material mismatch or deleted/cancelled candidate enters `needs_attention` and cannot produce a
second PO. Manual create/update and import finalization share a database-enforced derived identity,
including soft-deleted history. The canonical Purchase Orders list shows accessible manual,
email-created, and manual-then-supplier-confirmed provenance; Supplier Order Imports remains the
audit/retry/exception queue rather than a competing order list.

The implemented technician surfaces are Supplier Order Imports at
`/tech/storage/supplier-order-imports`, the import detail/correction workflow, and the immutable
sanitized **Email Copy** card on the otherwise canonical Purchase Order detail. Technical source and
import evidence remains on the import detail; trusted authentication evidence remains internal and
is not displayed. Admin surfaces are Purchase Order
Automation at `/tech/admin/settings/storage/purchase-order-automation`, Supplier Order Profiles at
`/tech/admin/settings/storage/supplier-order-profiles`, the existing Email and Signal rule builders,
Documentation Suppliers, and Integration AI Privacy/workloads. The profile UI supports no-AI
creation, immutable versions, test/replay, protected fixtures, activation, pause, retire, rollback,
and import/export. Optional governed AI can extract uncertain documents, repair an eligible import,
and propose or auto-activate a declarative profile version only after the configured replay/shadow
gates. The workflow records an order placed elsewhere; it never submits an order and never receives
goods or changes stock.

Deployment record (2026-08-05; operational and automated evidence, not human approval):

- The complete code and documentation set is deployed in `/var/Projects/tdPSA` with the required
  Laravel runtime permissions and `umask 0002` for Artisan operations.
- Migrations `2026_08_05_100000` through `2026_08_05_113000` were reviewed with
  `migrate --pretend` and applied to the actual Dev schema, including the Email UID live cursor.
- `PermissionSeeder`, `RoleSeeder`, `VendorSeeder`, `ShippingCarrierSeeder`,
  `SupplierOrderProfileLibrarySeeder`, `DocumentationKnowledgeDocumentationSeeder`, and
  `StorageKnowledgeDocumentationSeeder` were run on Dev.
- Laravel caches were cleared, Blade views were compiled, and the affected Documentation, Storage,
  Email, Signal, and Integration sources were synced into Nexum Knowledge. The five modules contain
  17 published articles; their external BookStack push remains pending because the current push
  worker processes all pending Knowledge content rather than a selected module set.
- An isolated Supplier Orders runtime is installed for `/var/Projects/tdPSA`: a locked database
  worker for queue `supplier-orders`, dispatch and heartbeat every minute, health every five
  minutes, retention daily at 04:10, and digest daily at 07:00. Each task uses a separate `flock`
  lock and the shared Supplier Orders runtime log.
- A separate locked inbound Email runtime now polls active account 1 (`support@tronderdata.no`)
  every minute and runs only the database `email,default` queues. The global Laravel scheduler
  remains deliberately uninstalled, so unrelated scheduled workloads were not activated.
- Controlled Itegra calibration was activated on 2026-08-05: global policy revision 3 is `shadow`,
  warehouse 2 (`Trønder Data`) is pinned, active profile row 1/version row 7 (version 4) passed its
  protected fixture 1/1, and exact preclassification Email rule 10 and Signal rule 2 are active. The
  Email rule is limited to inline forwards from `sveintore@tronderdata.no` to
  `support@tronderdata.no` with the expected subject and Itegra body markers. AI, supplier creation,
  Item creation, and Purchase Order writes remain disabled in this shadow revision.
- The first real forward was preserved as EmailMessage 51 and produced exactly one matched Email
  rule log, Signal 10, Signal execution 2, and import 2. Deterministic extraction found external
  order 2004138603 and one unresolved line for supplier SKU 1311386, quantity 1, line total 223.20.
  The import stopped at `needs_attention` / `policy` / `shadow_complete`; it created no Item,
  Purchase Order, receipt, Stock Unit, Movement, or on-hand change.
- The normal poll did not originally reach IMAP UID 1447 because more than 100 older unread messages
  filled the unseen window, so the exact UID was ingested once to complete the shadow trace. The live
  message also exposed that unsupported Webklex `getHeaders()` access stored an empty `headers_json`.
  Ordered raw headers are now parsed from `getHeader()->raw`.
- An intermediate paged-unread correction was unsafe in the established mailbox: its live safety run
  imported 240 historical messages and created 179 Tickets, 42 Signals, 18 Email rule logs, and one
  database-only TicketAssigned notification. Notification settings had mail and Nextcloud Talk off,
  no outbound Email log was created, and failed jobs remained zero. Polling was paused immediately.
  No accidental EmailMessage, Ticket, Signal, rule-log, or notification row has been deleted; cleanup
  requires explicit user authorization after exact targets are re-resolved.
- Migration `2026_08_05_113000` and the final poller correction now persist each account's
  `UIDVALIDITY`, establish `UIDNEXT - 1` as a forward-only baseline, drain the oldest genuinely new
  UIDs in bounded batches, ignore historical unread state, serialize account fetches, and fail closed
  if the UID namespace changes. Baseline, ordinary manual poll, and a scheduled cron cycle were
  live-verified at UID 1447 / 290 stored messages with no new queue row, message, or failed job.
- A read-only direct IMAP recheck of UID 1447 found 27 header names, three ordered `Received` values,
  one `Authentication-Results`, first receiving hop/authserv `plesk.tronderdata.no`, and aligned DKIM
  for `tronderdata.no`. The already-pinned import remains immutable with its original empty auth
  snapshot. Trust settings remain empty until an operator confirms that the Plesk boundary removes
  untrusted inbound authentication headers and a second message proves normal capture end to end.

Mailbox calibration record (2026-08-07; operational and data evidence, not human approval):

- A bounded read-only IMAP review used `EXAMINE` and `BODY.PEEK` after recording mailbox
  `UIDVALIDITY`, UID, message, and unseen baselines. Relevant order confirmations, including Trash
  samples, were read without changing mailbox flags or enabling new inbound routing.
- Itegra profile row 1 received validated candidate version row 8 (version 5). The candidate removed
  a false HTML spacer-row path and replayed the synthetic fixture, existing forwarded import 2, and
  three direct confirmations. Active version row 7 (version 4) and its exact shadow routing remain
  unchanged. The profile now has one synthetic and four protected real fixtures.
- Five additional profiles were added with validated versions and six protected real fixtures:
  Dustin, iFixit, MyTrendyPhone, Ecoengros, and IPC-Computer. Every profile remains an inactive
  `draft` with no active-version pointer, Supplier mapping, automation actor, Email rule, or Signal
  rule. Their profile overrides remain fail-closed in shadow with AI and master-data creation off.
- Nine installation-local calibration imports retain 12 reviewed lines in `needs_attention` /
  `validate`. The guarded transaction and independent count checks recorded no Vendor, Item,
  Purchase Order, shipment, receipt, Stock Unit, Movement, on-hand, Email-rule, Signal, Ticket,
  queue, attempt, repair, or failed-job side effect. Calibration imports have no Email, Signal, or
  Purchase Order links.
- No passing profile was created for NDI because its order facts are PDF-only, for 3DJake because
  normalized text omits line prices, or for Allnet because the available sample lacks order lines.
- Shipment-confirmation messages were retained only as future design corpus. Shipment-email
  automation is not implemented and requires a separate approved Level 3 RFC, ADR, and Feature
  Slices before any routing or shipment/tracking mutation.

Remaining controlled-rollout actions:

1. Forward another real Itegra confirmation inline from the exact calibrated forwarding address.
   Let the ordinary poller ingest it without exact-UID assistance and verify ordered stored headers
   plus one Email log, one Signal, and one idempotent `shadow_complete` import trace. Preserve it as
   an additional fixture when it expands current coverage. Historical mailbox calibration does not
   prove the ordinary authenticated polling path.
2. Confirm operationally that `plesk.tronderdata.no` strips untrusted inbound
   `Authentication-Results` before adding its own result. Only then configure both trusted authserv
   and first receiving-hop identifiers and activate a profile version that requires trusted aligned
   authentication. Decide whether production uses the exact internal forwarder or direct supplier
   delivery. Configure a least-privilege automation actor and Item mappings before any write mode.
3. If AI will be used, configure the effective Integration workload, non-writing agent,
   provider/model/privacy policy, budget, and recipient governance, then complete a controlled
   provider smoke before `auto_verified_ai` or automatic profile activation.
4. Use a module-scoped BookStack push, or explicitly review every unrelated pending Knowledge item
   before authorizing the current global push worker. Verify the 17 affected articles after push.
5. Decide whether to authorize recoverable cleanup of the 240 accidental historical Email rows and
   their 179 Tickets, 42 Signals, 18 rule logs, and one database notification. Re-resolve and preview
   exact targets before any destructive or hiding action.
6. Complete authenticated browser and named human review before describing the automation as
   active or production-ready.

No frontend build is expected for this server-rendered update. Disabling the global policy and
profiles is the operational rollback. Created imports, attempts, mappings, profile versions, Items,
and Purchase Orders remain audit history and must not be deleted or schema-rolled back after use.

Current deployment state at this documentation update: the Dev deployment baseline, isolated
runtimes, exact shadow routing, and first real Email-to-Signal-to-Storage trace are complete. The
real import reached deterministic `shadow_complete` with one unresolved line and no Item, Purchase
Order, receipt, Movement, or stock write. Header capture and polling defects discovered by the test
are corrected with ordered raw-header parsing and a persistent forward-only UID namespace/baseline;
live baseline, manual poll, and cron checks caused no further historical ingest. The intermediate
safety test's 240 historical Email rows and linked side effects remain intact pending an explicit
cleanup decision; it created no outbound email and no failed job. A second ordinary-poll capture,
broader Itegra fixture coverage for multi-line orders, quantity above one, and freight or discount
variation, verified/configured Plesk trust boundary, least-privilege actor, Item mapping, controlled
external BookStack push, optional governed AI provider smoke, authenticated browser QA, broad
post-fix regression, and named human review remain outstanding. The Codex
in-app browser could not perform
authenticated UI verification because the self-signed `nexum-psa.local` certificate is rejected
with `ERR_CERT_AUTHORITY_INVALID`; this is a browser trust blocker, not evidence that the
application workflow passed or failed. The installed and enabled Chrome extension also remained
unavailable because the Browser plugin's native-host manifest and registry entry are missing;
reinstalling that plugin is required before Chrome control can be retried.

Risks: a forged or misrouted message could create a false commercial record; layout drift or an AI
error could alter identity, quantity, price, freight, discount, currency, or totals; repeated and
changed resends could duplicate or conflict with an order; unsafe matching could contaminate Item
identity; automatic Supplier or Item creation could create master-data noise; AI egress could expose
purchase or delivery data; an incorrectly activated profile could affect later imports; a disabled
actor, provider outage, or missing worker could leave a hidden backlog; and notification tests could
send unexpected messages. Server-enforced trust, evidence, arithmetic, caps, permissions,
idempotency, immutable versions, fixtures, rollback, circuit breakers, retention, and operational
health must all remain effective. Before any controlled notification test, verify the current
recipient notification settings. The open Integration reviews `HR-2026-07-29-012` and
`HR-2026-07-27-001`, Signal review `HR-2026-07-15-002`, and base purchase/receiving review
`HR-2026-08-04-001` remain separate and are not completed by this entry.

Final pre-rollout automation verification on 2026-08-05 is green. After the Email header and
forward-only UID hardening, the full Laravel suite passed 1,206 tests / 10,039 assertions. The final
focused Email run passed 61 / 366, including baseline initialization, oldest-first new-UID batch
draining, raw repeated-header preservation, and fail-closed `UIDVALIDITY` change handling; the
earlier combined affected automation package passed 341 / 3,086. The six changed Email cursor files
and migration passed syntax/Pint checks, and repository-wide `git diff --check` passed. HTTP smoke
returned 200 for login and the expected 302 login redirects for protected Purchase Orders and Inbox
without any 500 response. The isolated runtimes advanced over consecutive minutes with zero Email,
default, or Supplier Orders failed jobs; live Email polling remained at UID 1447 / 290 stored rows
with no further historical ingest. Passing automated tests does not complete any human checkbox.

The final pinned-governance/source-card hardening pass is green on the current Dev tree: canonical,
profile-library, and policy-boundary suites passed 15 tests / 101 assertions; manual correction and
automation UI passed 15 / 249; and import pipeline plus governed AI passed 20 / 213. This automated
evidence does not change the `Pending` review status.

The final combined data-integrity regression package is green on the current Dev tree: singleton/
current guards, append-only attempts, atomic profile health, dispatch claim-CAS, Operations,
Pipeline, Profile Engine, governed AI, and policy validation pass 81 tests / 644 assertions.
MariaDB 10.6 probe tables verified the invisible generated unique guard and attempt update/delete
triggers, then were removed. Migration `112000` was subsequently reviewed with
`migrate --pretend` and applied to the actual Dev schema together with migrations `100000` through
`111000`. This records deployment completion only; the database guards, append-only behavior, and
rollback preflight remain part of the outstanding human verification below.
The rollback preflight is also covered and stops before removing any guard once append-only start/
completion history can no longer satisfy the legacy uniqueness constraint.
The queue regression also proves a duplicate delivery cannot complete a claim already owned by a
running worker. The Pipeline source fixture now uses only the documented synthetic order, buyer,
product, SKU, and amounts; a repository search found none of the original commercial fixture values.

The 2026-08-07 mailbox calibration replayed all 11 stored fixtures successfully against their
intended validated candidate versions, including 5/5 for Itegra version row 8. Focused profile,
fixture, policy, integrity, and pipeline verification passed 46 tests / 419 assertions. An
unauthenticated HTTPS smoke returned the expected 302 login redirect without a 500 response. This
calibration changed installation data and review evidence only; it requires no migration, cache
clear, frontend build, or Knowledge sync. Passing verification does not complete a human checkbox.

The Admin Storage navigation regression reported on 2026-08-07 is corrected on Dev. The Storage
card now exposes Inventory settings, Supplier order policy, and Supplier profiles. The focused
dashboard regression passed 1 test / 12 assertions, and the supplier automation UI suite passed
7 tests / 108 assertions. Authenticated browser confirmation remains open for the reasons above.

Supplier-order identity reconciliation deployment gate (added 2026-08-07; still pending):

- The sanitized preflight counted one Purchase Order, zero populated supplier-order numbers, and zero
  normalized collisions across active and soft-deleted history before schema mutation.
- Migration `2026_08_07_100000_add_supplier_order_identity_to_purchase_orders.php` remains recorded
  in batch 62. Forward-only compatibility migration
  `2026_08_07_101000_add_database_generated_supplier_order_identity_key.php` ran in batch 63. The
  authoritative boundary is now a database-generated normalized key with a composite supplier/key
  unique index; the former hash unique index is removed. The existing blank identity remains `NULL`.
  The obsolete nullable hash column remains unindexed only for an explicit later cleanup after the
  human-review/rollback window.
- A live MariaDB 10.6.23 contract used two independent connections and verified supplier scoping,
  blank references, raw insert and raw update rejection, database-consistent Unicode case handling,
  a stale REPEATABLE READ plain read, and a current locking read that sees the committed race winner.
  The temporary contract table was removed. Manual create/update, import finalization, source
  projection, soft-deleted history, material totals, and atomic pre-history AI repair are covered.
- Focused identity/import verification passes 27 tests / 192 assertions; the affected AI, integrity,
  policy, and purchase package passes 71 / 622; and the complete Storage suite passes 247 / 2,528
  with the dedicated opt-in MariaDB PHPUnit contract skipped only because separate contract
  credentials are not configured. The equivalent live MariaDB contract above passed. The remaining
  application modules pass in three bounded groups: 978 tests / 7,619 assertions. Combined verified
  coverage is 1,225 passing tests / 10,147 assertions plus the one documented opt-in skip. Two
  monolithic runs were terminated only by the command time limit at 124 and 304 seconds.
- `optimize:clear`, Blade compilation, PHP syntax, Pint, three protected-route HTTP smoke checks,
  and Storage Knowledge sync (one chapter, six articles, zero skipped) pass. Both identity migrations
  report `Ran`, the queue reports zero failed jobs, and no frontend build is required.
- The batch-63 migration is intentionally additive/forward-only: its `down()` does not remove an
  invariant that may be owned by batch 62 on a fresh install. Any future removal of the legacy hash
  column requires a separate forward migration after review.
- The isolated worker is a bounded 50-second cron process, so it loads current code on each minute's
  next cycle and does not require a persistent-worker restart. Its dispatch, heartbeat, health,
  retention, and digest cron entries remain installed.
- Authenticated visual QA remains open: the in-app Chrome connector cannot start because the local
  Codex native-messaging host registration/manifest is absent even though the extension is enabled.
  Reinstall the Browser plugin through the Codex plugin UI before completing the browser checks.
  Keep the review `Pending` until a named human confirms the shared identity and no-stock boundary.

Human checks:

- [ ] Open **Admin** and confirm the **Storage** card shows **Inventory settings**, **Supplier order
  policy**, and **Supplier profiles**. Open the last two links and confirm they reach the policy and
  profile pages without a dead link or permission error for an authorized administrator.
- [ ] On the deployed Dev schema, confirm in an authenticated browser that the global policy remains
  `shadow`, exact Itegra Email rule 10 and Signal rule 2 have not widened, and mailbox calibration
  imported no historical Email backlog. Confirm active Itegra version row 7 remains unchanged and
  candidate version row 8 is validated but inactive with one synthetic and four real fixtures.
  Confirm Dustin, iFixit, MyTrendyPhone, Ecoengros, and IPC-Computer remain inactive drafts without
  active versions, automation actors, Supplier mappings, Email rules, or Signal rules.
- [ ] Verify the configured service actor is active and has exactly the required Storage and, when
  enabled, Documentation permissions. Disable or deauthorize the actor and confirm finalization
  fails closed with an actionable operational reason.
- [ ] Verify the installed isolated Supplier Orders runtime is persistent, owned by the intended Dev
  account, rooted at `/var/Projects/tdPSA`, and distinct from other application workers. Confirm
  current worker and scheduler heartbeats, one non-overlapping dispatch, bounded retry, stale
  recovery, health/maintenance, retention, and optional digest execution through the separate
  `flock` locks and runtime log. Confirm the global Laravel scheduler remains deliberately inactive
  until the separate cross-application Operations review authorizes it.
- [ ] Without enabling AI, create or clone a supplier profile, add a protected fixture from a
  reviewed import, edit an immutable draft version, preview/replay it, activate it, create a later
  version, roll back, export/import safe configuration, and confirm source email, credentials, and
  installation-specific secrets are absent from the export.
- [ ] Edit profile metadata and verify the audit records the actor, changed fields, and exact
  before/after values while the active version, definition, and checksum remain unchanged. Confirm
  an invalid selector, duplicate slug, and unauthorized edit are rejected without creating an
  audit entry.
- [ ] Review the four protected real Itegra fixtures now present, including the forwarded and direct
  template paths, then add enough approved confirmations to cover several lines, quantity above one,
  freight or discount variation, and missing optional references. Keep policy in shadow, confirm
  every fixture checksum is protected, and review evidence plus arithmetic before approving
  thresholds or activating candidate version row 8.
- [ ] Configure an exact pre-routing Email rule and matching Signal action. Ingest one trusted Itegra
  confirmation and confirm exactly one Signal and one Storage import are created, ordinary Ticket
  ingress is suppressed only for the configured match, and unrelated email retains normal behavior.
- [ ] Repeat the message, retry Email/Signal/queue processing, and run concurrent dispatch where
  practical. Confirm the existing import/PO is reused. Send a changed resend for the same external
  order and confirm it becomes a visible revision/conflict rather than a second PO.
- [ ] Register an active manual PO with a supplier order number and reviewed material lines, then
  process a trusted matching confirmation. Confirm the import links to that exact PO, the canonical
  list still has one order, and provenance changes from manual to manual-plus-supplier-confirmed.
  Confirm its internal number, creator, lines, dates, warehouse, currency, status, shipments, and
  receipts remain unchanged and no Stock Unit, Movement, receipt, or on-hand change occurs.
- [ ] Repeat with a material line/quantity/Item/SKU/currency/warehouse/price/tax/total mismatch and
  separately with deleted and cancelled matching candidates. Confirm every case enters
  `needs_attention` with an actionable candidate/reason and no duplicate, silent overwrite, or
  historical resurrection.
- [ ] Try a manual create against an email-created PO identity and update another PO onto an occupied
  identity, including a soft-deleted owner. Confirm validation rejects each collision. Confirm the
  same external number is accepted for a different supplier and a blank supplier number remains
  allowed but unmatched. Confirm surrounding spaces/database case normalize while leading zeros,
  punctuation, tabs/newlines, and internal spacing remain identity-significant. Confirm ordinary
  editing cannot change the supplier identity after vendor confirmation.
- [ ] Inspect the migrated Dev schema/data and confirm every nonblank historical supplier order has
  the expected database-generated identity, blank identities remain `NULL`, soft-deleted identities
  stay reserved, the obsolete hash is not indexed, raw insert/update cannot bypass the composite
  supplier/key constraint, and concurrent manual/import attempts cannot produce more than one PO for
  the same domain identity.
- [ ] Open Purchase Orders and confirm accessible provenance distinguishes manual, email-created, and
  manual-then-supplier-confirmed rows in the same list. Confirm the corresponding Supplier Order
  Import is an audit/provenance row, not a second editable PO, and that its page remains the
  audit/retry/exception queue.
- [ ] Confirm the database retains several historical policies/versions but exactly one current
  policy and one active version per profile. Verify one processed import has separate append-only
  stage-start/stage-completion attempts, retention leaves those attempts unchanged, and delayed
  dispatch/job failure callbacks cannot overwrite a completed or newer claim.
- [ ] Open Supplier Order Imports and exercise search, status/stage/profile filters, sorting,
  pagination, retry, rejection, and the detail trace. Confirm the safe source snapshot contains no
  raw EML, unrestricted headers, remote images, active links, credentials, prompt, or raw model
  response.
- [ ] On an eligible nonterminal import, use the structured manual correction form for supplier,
  external order, date, currency, warehouse, lines, prices, tax, charges, and totals. Supply the
  required reason and confirm immutable source/fingerprint data is unchanged, the correction is
  audited, and no Vendor, Item, PO, receipt, or stock side effect occurs until an explicit later
  action permits it.
- [ ] Resolve one line through an exact existing supplier-SKU mapping, one through a manually chosen
  Item, and one through the permitted distinct-Item workflow. Confirm mappings are reused, several
  matches remain ambiguous, leading zeros/punctuation are preserved, name-only similarity never
  merges Items, and Supplier bootstrap uses Documentation provenance and permissions.
- [ ] After shadow approval, enable the narrow deterministic review/automatic policy and process one
  safe order. Confirm exactly one PO is registered as `ordered`, the ordered date uses source data or
  the documented pinned fallback without substituting today's date, source commercial and delivery
  facts remain visible on both source cards, the effective-policy checksum/snapshot differs clearly
  from its global revision, and the PO source card links to the immutable import trace.
- [ ] Test the original Inbox link with and without Email Inbox permission and after simulated Email
  retention. Confirm the link is permission-protected while the safe Storage snapshot remains.
- [ ] With effective Integration governance, run one controlled AI extraction and **Repair with AI**.
  Confirm the agent has no tools or action authority, output is strict/evidence-backed, every hard
  Storage gate is recomputed, the repair is auditable, and a proposed profile version cannot become
  active until required fixture and shadow gates pass. Roll back a passing test version.
- [ ] In Repair history, confirm the current repair is `Applied`, an earlier repair is `Superseded`,
  and a guarded proposal is `Blocked`. Confirm blocked proposals expose neither Apply nor Reject,
  and that **Reprocess** appears only for the current applied, retryable repair when no Purchase
  Order exists; the resulting Purchase Order link must remain permission-gated.
- [ ] Inspect the bounded Repair input/output evidence and confirm it contains no address, raw source
  body, unrestricted header, contact/payment/footer data, embedded instruction, raw prompt, or raw
  model response. Confirm profile match scope is restored and validated only on the server.
- [ ] Confirm Repair displays configured budget/limit, spent, remaining, currency, provider cost,
  consensus evidence, access decision, and checksums without exposing restricted content. Verify
  candidate reproduction and related links are permission-gated.
- [ ] Disable AI or make the provider/workload unavailable. Confirm deterministic-safe imports
  continue, AI-required imports retry or enter a clear exception, no raw-data fallback occurs, and
  provider/model denial never widens policy.
- [ ] Verify the sender-authentication boundary with a trusted real receiving path: authentication
  service evidence alone is rejected, the exact configured first receiving hop plus trusted
  authentication service succeeds, and a forged or rearranged `Received` header fails closed.
  Confirm unrelated Email-to-Ticket processing still works.
- [ ] With more historical unread mailbox messages than the configured batch, forward enough new
  controlled messages to exceed one poll batch. Confirm the ordinary poller drains only the new UIDs
  oldest-first over later cycles without exact-UID assistance, while historical unread messages stay
  untouched. Verify `headers_json` contains separate ordered `received` and
  `authentication-results` arrays, stored/soft-deleted UIDs remain idempotent, one account cannot have
  overlapping fetch jobs, and a simulated `UIDVALIDITY` change fails closed with a visible error.
- [ ] Review the accidental historical-import preview (240 Email rows, 179 Tickets, 42 Signals,
  18 rule logs, one database notification, no outbound email). Explicitly authorize or decline
  recoverable cleanup; if authorized, re-resolve exact targets, preserve audit evidence, execute the
  bounded cleanup, and verify no intended pre-existing record was changed.
- [ ] Exercise amount, line, quantity, currency, arithmetic, authentication/alignment, warehouse,
  Item ambiguity, new-master-data cap, and unauthorized-actor failures. Confirm even a reported
  100% AI confidence cannot bypass one hard gate.
- [ ] Create shipment/cancellation/receipt history on a separate test PO and confirm manual or AI
  repair becomes preview-only for locked facts. Confirm no supplier-order email, shadow run, retry,
  correction, profile replay, or AI path creates a Purchase Receipt, Stock Unit, Movement, or
  on-hand change; only a later explicit goods receipt may update inventory.
- [ ] Verify view, resolve, execute, profile-manage, policy-manage, purchase-manage, Email, Signal,
  Documentation, Integration, and notification permissions independently. No rule builder or read
  permission may imply Storage or Supplier write access.
- [ ] Before any Dev UI test, run `PermissionSeeder` and confirm the required permission catalogue
  rows exist. In a controlled Dev check, verify that an absent required catalogue row returns HTTP
  403 instead of granting access, then rerun `PermissionSeeder` before continuing the authenticated
  Supplier Order Automation UI review.
- [ ] Push the 17 affected published Knowledge articles to BookStack through a module-scoped path,
  or explicitly review and authorize every unrelated pending Knowledge record before using the
  current global worker. Confirm the target pages and chapters are synced without changing unrelated
  documentation.
- [ ] In a browser that trusts the Dev certificate, review policy, profiles, import queue/detail,
  manual correction, PO source card, Email/Signal rule configuration, Supplier bootstrap provenance,
  AI governance, Repair cards/history, and health views on desktop and narrow/mobile widths,
  including keyboard navigation, errors, disabled reasons, and safe HTML. Record certificate
  remediation or the trusted-browser method used; the current `ERR_CERT_AUTHORITY_INVALID` block
  does not satisfy this check.
- [ ] After the remaining real-email, shadow, optional provider, and manual checks plus any resulting
  corrections, rerun the focused affected suites, combined Email/Signal/Storage/Integration/
  Documentation/Notification/permission/receiving regression, full Laravel suite, Blade
  compilation, route checks, migration status, Knowledge sync status, trusted HTTP/browser smoke,
  file permissions, and `git diff --check`; record final counts and results here.

Reviewer:
Reviewed date:
Result / notes: Dev deployment, migrations, seeders, Knowledge import, isolated runtimes, exact
Itegra Email/Signal rules, active shadow profile, and one real forwarded shadow trace are complete.
EmailMessage 51 led to Signal 10 and import 2 with deterministic `shadow_complete`, one unresolved
SKU line, and no Item, Purchase Order, receipt, Movement, or stock write. Exact ingestion was needed
because the original poll was pinned by unread backlog, and that row has no stored authentication
headers. Raw-header capture is fixed, and automatic polling now uses a persisted forward-only
`UIDVALIDITY`/UID baseline that passed live initialization, ordinary poll, and cron checks without
further historical ingest. The intermediate safety test imported 240 historical messages and
created 179 Tickets, 42 Signals, 18 rule logs, and one database-only assignment notification; no
outbound email or failed job resulted, and no cleanup has been performed without explicit approval.
Direct IMAP evidence identifies `plesk.tronderdata.no` and aligned DKIM, but trusted settings remain
empty until the sanitizing boundary and a second ordinary-poll capture are verified. The external
BookStack push is pending because the worker is global, and authenticated Codex browser QA remains
blocked by the self-signed Dev certificate (`ERR_CERT_AUTHORITY_INVALID`). The cleanup decision,
broader Itegra fixture coverage for multi-line, quantity-above-one, and freight or discount
variation, least-privilege actor, Item mapping, controlled provider smoke if AI is enabled, and
explicit review by a named human remain outstanding. The 2026-08-07 mailbox calibration added five
inactive draft profiles with six protected real fixtures plus a validated inactive Itegra version 5
candidate with four real fixtures. It did not activate routing or produce commercial, inventory,
Email-rule, Signal, Ticket, queue, or failed-job side effects. NDI, 3DJake, and Allnet remain
unsupported for the recorded reasons, and shipment confirmations remain future corpus requiring a
separate Level 3 change. The manual/email identity reconciliation is deployed through batch 63 with
the database-generated composite guard, two-connection MariaDB race evidence, 1,225 passing tests /
10,147 assertions across bounded suites, and no receipt or stock side effect. Its generated identity,
ordinary-edit lock, material comparison, provenance presentation, and no-stock behavior still
require the unchecked named-human steps above. Keep status `Pending` until a named reviewer begins
these checks; never infer `In Review` or `Reviewed` from deployment or passing automation.

### HR-2026-08-04-002 - Sortable Storage Inventory Queues, Admin Lists, And Detail History

Status: Pending
Added: 2026-08-04
Environment: Dev
Related: `docs/rfc/2026-08-04-storage-inventory-sortable-tables.md` and its two linked
2026-08-04 Feature Slices

Scope: All eleven approved read-only Storage table surfaces now use one accessible sortable-heade
contract. Inventory Items, Picking List, Purchase Orders, and Receiving use allowlisted server-side
SQL sorting. Admin warehouses, Item Movement History, Box Contents, Box Events, Purchase Order
Lines, Shipment Allocation Lines, and Receipt History use typed sorting of already-loaded
collections with independent parameter names on multi-table pages. Missing optional values stay
last, computed values match the UI, and stable ties prevent row shuffling. Action columns,
purchase/shipment/receiving input tables, and the printed control slip remain intentionally fixed.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Storage --no-interaction`. No migration, seeding, permission update,
queue restart, scheduler change, external connector setup, or frontend build is required.

Risks: a sort must never alter stock or workflow data; allowlists must prevent query input from
becoming SQL; calculated available, progress, status, and outstanding order must match the displayed
values; missing data must not jump ahead on descending sorts; active filters must survive while a
new sort leaves stale pagination behind; and sortable controls must not be added to indexed input or
printed receiving rows.

Automated verification: the complete Storage suite passes with 95 tests and 1,233 assertions. The
combined relevant regression package passes with 64 tests and 883 assertions: 55 tests / 736
assertions for the four operational queues and 9 / 147 for the collection sorter plus seven
Admin/detail surfaces. All 60 operational queue column/direction expressions execute successfully
against the configured Dev MySQL database. The complete Laravel suite passes with 1,027 tests and
8,558 assertions. PHP syntax, targeted Pint, Blade cache compilation, diff/whitespace checks, and
file permission checks pass.

Human checks:

- [ ] On desktop, open Inventory Items, Picking List, Purchase Orders, and Receiving. Click every
  linked data heading in both directions and confirm row order, the active arrow, one `aria-sort`
  state, numeric alignment, missing values last, and stable equal-value ordering.
- [ ] Apply search and every available filter in each paginated queue, change sorting, and confirm
  search/filter values remain while the result starts on page one. Remove the explicit sort and
  confirm each queue returns to its documented default operational order.
- [ ] In Inventory Items, compare On-hand, Reserved, Available, and Status sorting with the displayed
  quantities and `Should order`/`OK` badges, including an over-reserved item and an item at its
  reorder point.
- [ ] In Picking List, verify Status sorting distinguishes Ready, Waiting for stock, and Requires
  identified-unit picking exactly as shown, and confirm the Pick/Open ticket action column is not a
  sort control.
- [ ] In Purchase Orders and Receiving, verify ordered/expected dates keep missing dates last and
  that progress, shipment count, received, and outstanding sorts match the displayed aggregates.
- [ ] Open Admin Inventory, one Item with several movements, and one Box with contents and events.
  Verify every supported heading in both directions and confirm the two Box tables retain
  independent sort choices and return to the correct anchor.
- [ ] Open a Purchase Order with several lines, shipment allocations, and receipt entries. Sort all
  three table groups independently, confirm one choice does not reorder the others, and verify
  actions such as Cancel and Reverse remain plain headings.
- [ ] Open purchase-order edit, shipment registration, goods receiving, and the printable control
  slip. Confirm no sorting controls appear and the input/print row order is unchanged.
- [ ] At narrow mobile width, confirm every table remains usable through its responsive horizontal
  container. Use keyboard navigation on desktop to focus and activate several sort links and confirm
  the announced label describes the next direction.

Reviewer:
Reviewed date:
Result / notes: Automated implementation and Dev verification are complete. Awaiting explicit named
human review of the visual, responsive, keyboard, and real-data ordering checks above.

### HR-2026-08-04-001 - Supplier Purchase Orders, Shipping, And Goods Receiving

Status: Pending
Added: 2026-08-04
Environment: Dev
Related: `docs/rfc/2026-08-04-storage-purchase-orders-shipping-receiving.md`, the four linked
2026-08-04 Feature Slices, and the procurement/carrier and immutable-receipt ADRs

Scope: Storage can register supplier orders that were placed outside Nexum, retain compatible
Ticket-created purchase needs, follow several shipments and tracking identifiers, print a control
slip, and post accepted or rejected quantities one delivery at a time. Accepted goods update
inventory through an idempotent immutable receipt ledger; rejected goods remain outstanding for
replacement. Serial, batch, and expiry units are supported. Reasoned cancellation, separately
authorized overage, guarded receipt reversal, least-privilege API parity, and a Documentation-owned
Shipping Carriers register complete the workflow. Nexum still does not submit supplier orders or
poll carriers.

Deployment actions: deploy the code; run `php artisan migrate --force`; run `php artisan db:seed
--class=PermissionSeeder --force`, `RoleSeeder`, `ShippingCarrierSeeder`,
`DocumentationKnowledgeDocumentationSeeder`, and `StorageKnowledgeDocumentationSeeder`; run
`php artisan optimize:clear`; then run `php artisan knowledge:sync-docs --module=Documentation
--no-interaction`, and repeat the sync for `Storage`, `Ticket`, and `Integration`. No queue restart,
scheduler change, external connector setup, or frontend build is required.

Risks: a repeated receipt must never double-post stock; shipment allocations, rejected replacements,
cancellations, and reversals must not create false outstanding or received quantities; serial and
batch history must remain isolated; tracking links must stay on verified HTTPS carrier hosts;
inactive master data and client-supplied metadata must not bypass action invariants; Ticket identity
must remain hidden without Ticket permission/scope; and only an eligible, still-available receipt
may be reversed.

Automated verification: the full feature baseline passes the complete Laravel suite with 1,011 tests
and 8,246 assertions. The combined affected Documentation, Storage, Integration, and Ticket run
passes with 167 tests and 1,791 assertions. After the order-date and sortable-index follow-ups, the
current purchase workflow passes with 24 tests and 262 assertions. The two focused sorting
regressions pass with 45 assertions across every visible column, both directions, current-view link
preservation, invalid-direction fallback, date null placement, and aggregate progress, shipment, and
outstanding sorts. All 16 column/direction combinations also execute successfully against the
configured Dev database. The final focused backend receipt suite passes with 24 tests and 206
assertions;
the API suite passes with 5 tests and 122 assertions; and the Ticket-scope/workflow run passes with
26 tests and 279 assertions. All 100+ changed PHP files pass syntax and targeted Pint checks, Blade
cache compilation passes, OpenAPI generation contains all 12 purchase operations, and the 12
purchase API routes are registered.

Dev deployment verification: the three Storage purchase migrations ran in batch 57 and the carrier
migration remains applied in batch 56. Permission, role, carrier, Documentation Knowledge, and
Storage Knowledge seeders all complete successfully. The Storage Knowledge seeder now preserves an
existing BookStack-owned canonical chapter or article while adding the purchase article; its focused
regression passes with 1 test and 8 assertions, and the full Knowledge suite passes with 40 tests and
365 assertions. The resulting Knowledge sync reports Documentation 1 chapter/2 articles, Storage
1/5, Ticket 1/11, and Integration 1/6, with zero skipped entries in all four modules. The canonical
Storage chapter and inventory article remain owned by `book_stack` after the compatibility run.

Runtime verification: `optimize:clear` succeeds. HTTPS smoke checks return the expected guest or API
responses: 302 for Purchase Orders, 302 for Shipping Carriers, and 401 for the unauthenticated
purchase-order API. Interactive visual verification remains open because the in-app browser refuses
the Dev environment's self-signed certificate with `ERR_CERT_AUTHORITY_INVALID`; the certificate
warning was not bypassed. No queue restart, scheduler change, external connector setup, or frontend
build was required.

Human checks:

- [ ] As a view-only user, confirm Purchase Orders, receipts, shipments, tracking, and the control
  slip are readable but no manage, receive, overage, or reversal controls appear.
- [ ] Search or filter the Purchase Orders list, sort every visible heading in both directions, and
  confirm the indicator, row order, active search/filter values, missing-date placement, and
  pagination remain correct.
- [ ] As an authorized manager, open a new purchase order and confirm Ordered date defaults to the
  current date and remains editable. Register a placed multi-line supplier order and confirm inactive
  or non-supplier vendors, inactive warehouses, and inactive or non-orderable new items are
  unavailable and rejected by the server.
- [ ] Add two partial shipments with several tracking identifiers, append a carrier handoff number,
  and confirm every earlier identifier remains visible and each clickable link opens only the
  expected carrier host.
- [ ] Print the control slip and confirm supplier, order, ordered, previously received, outstanding,
  and blank count fields are readable on paper or PDF.
- [ ] Receive one line partially and another line fully, including accepted and rejected quantities,
  and confirm only accepted quantity increases inventory while the rejected quantity remains
  outstanding for replacement.
- [ ] Receive serial-, batch-, and expiry-controlled examples and confirm identifier requirements,
  duplicate-serial rejection, stock-unit balances, and later receipt history.
- [ ] Select a specific allocated shipment and confirm only its outstanding allocated lines can be
  received; then verify an unknown-content shipment follows the unscoped allocation guard.
- [ ] Confirm normal overage is rejected, then use a user with the separate overage permission and a
  reason to post the intended exception exactly once.
- [ ] Retry the same receipt request and confirm no duplicate receipt, movement, or inventory change
  is created; changing the payload under the same token must be rejected.
- [ ] Exercise outstanding line cancellation, rejected replacement shipment, shipment cancellation,
  order close/cancel, and rejected-only reversal, and confirm allocation and order progress remain
  internally consistent.
- [ ] Reverse one still-available receipt and confirm compensating movements and reopened outstanding
  quantity; reserve or consume another received unit and confirm reversal is blocked.
- [ ] Verify `storage.purchase.read` without `tickets.read` returns no Ticket identifiers, subject, or
  Ticket-specific metadata; add `tickets.read` and confirm the linked Ticket context appears. Repeat
  the equivalent Tech page check with and without `ticket.view`.
- [ ] Check purchase-order detail, shipment entry, receiving, carrier register, and control-slip views
  at desktop and mobile widths, including validation feedback and print layout.

Reviewer:
Reviewed date:
Result / notes: Automated implementation and Dev deployment checks are complete. Awaiting named
human review on Dev, including the visual checks that could not be completed through the in-app
browser because of the self-signed certificate.

### HR-2026-07-29-013 - Production Ticket External-Message API Route

Status: Pending
Added: 2026-07-29
Environment: Production and Dev
Related: GitHub issue #195 and `docs/deployment/ticket-api-route-verification.md`

Scope: Ticket retains its module-owned `POST /api/v1/tickets/{ticket}/external-messages` route with
the `tickets.update` Sanctum ability. Regression coverage verifies route registration, a 403 response
for an authenticated token without the ability, idempotent internal-note storage for an authorized
token, and absence of customer email. The deployment runbook records the safe production checks.

Deployment actions: deploy the Ticket changes, run `php artisan optimize:clear`, and verify the route
list from the deployed application. No migration, permission seed, queue restart, scheduler change,
frontend build, or Knowledge sync is required for this regression check.

Risks: production may run an older build or stale route cache; a broad or incorrect token may hide
the intended 403 boundary; a smoke-test payload must remain internal and idempotent; and tokens,
customer content, and production identifiers must not enter logs, screenshots, or GitHub comments.

Automated verification: the focused regression suite passes with 3 tests and 14 assertions. The
complete Ticket feature suite passes with 165 tests and 1,278 assertions. An unauthenticated
production probe returns 401 rather than the previously reported 404, confirming that the route is
currently registered behind authentication.

Human checks:

- [ ] From the deployed application directory, confirm the route list contains the named POST route
  and the `tickets.update` middleware.
- [ ] With an approved token lacking `tickets.update`, confirm the production endpoint returns 403.
- [ ] With an approved `tickets.update` token and a clearly internal test Ticket, submit one unique
  internal note and confirm 201, then repeat the same request and confirm 200 with `created=false`.
- [ ] Confirm exactly one internal message was stored with the expected source/identifier and that no
  customer email or portal notification was sent.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-012 - AI Privacy Governance And Coordinator Worklog API

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #178, `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`,
and the 2026-07-14 AI/coordinator Feature Slices.

Scope: Integration owns a privacy-first installation maximum, provider/model/agent/workload
governance, policy evaluator, deterministic privacy gateway, read-only workload-bound tokens,
metadata-only access audit, finite retention cleanup, and Admin configuration. Existing outbound AI
chat paths pass through the evaluator and gateway. Report exposes minimized technician/time-entry
data, while Ticket and Task own their stale-record endpoints. Default responses use workload-scoped
aliases and exclude natural names, customer names, titles, descriptions, messages, notes, invoice
text, attachments, rankings, credentials, and secrets.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan db:seed
--class=PermissionSeeder --force`, `php artisan db:seed --class=RoleSeeder --force`, and
`php artisan optimize:clear`. Restart queue and scheduler workers so AI execution and
`ai.access.cleanup` use the new policy. Sync Knowledge documentation for Integration and Report.
Review the closed installation defaults before enabling any processing or creating a token.

Risks: the default policy intentionally disables AI until an Admin reviews it; missing/expired
provider or model approvals must block external calls; direct external mode must never bypass the
recorded gates; privacy washing reduces risk but is not anonymization; aliases must not expose source
IDs; ordinary broad API keys remain unchanged and need deliberate review; and coordinator responses
must never include names or free text.

Automated verification: the focused AI/coordinator governance suite passes with 7 tests and 62
assertions; AI telemetry passes with 5 tests and 72 assertions; AI/rightbar Integration regression
passes with 15 tests and 97 assertions; API-key least-privilege tests pass with 4 tests and 102
assertions. Lead Intelligence passes with 33 tests and 277 assertions, Marketing with 33 tests and
482 assertions, and Signal with 27 tests and 157 assertions after all AI callers were aligned with
the explicit policy gate. The complete application suite passes with 940 tests and 7,462 assertions;
the post-format affected-module run passes with 190 tests and 1,949 assertions. PHP syntax, Pint,
Blade compilation, Git diff checks, and migration status pass, with all migrations applied on Dev.

Human checks:

- [ ] Open Admin -> Integrations -> Privacy & Coordinator and confirm the initial policy is AI off,
  external off, privacy gateway on, direct external off, local-only, and aggregate maximum.
- [ ] Save a policy revision and confirm revision number, reviewer, time, and reason are visible in
  persisted history.
- [ ] Try to approve a provider/model/agent or workload wider than the installation maximum and
  confirm it is rejected with a useful explanation.
- [ ] With one approved test provider/model, confirm local-only and privacy-relay requests work and
  an incomplete, disabled, rejected, or expired approval blocks the request without external egress.
- [ ] Submit synthetic email, phone, token, and password patterns through the safe privacy-gateway
  test path and confirm they are removed; confirm a failed rewrite/post-validation fails closed.
- [ ] Create one narrow coordinator workload/token and call all four API families. Confirm aliases
  are stable within the workload and no name, customer name, title, description, message, note,
  billing text, attachment, ranking, credential, or secret appears.
- [ ] Confirm date range, page size, rate, expiry, network, missing-scope, write-scope, and revoked
  token failures use stable reason codes and create metadata-only audit rows.
- [ ] Confirm ordinary API keys start with all scopes unchecked, empty selection fails, full access
  needs a separate confirmation, and existing broad keys are only flagged for review.
- [ ] Run the retention cleanup with expired test audit/payload records and confirm only records past
  the configured finite retention are deleted.
- [ ] Review the Admin page and API error responses at desktop/mobile widths and with an Admin lacking
  the new management permissions.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-011 - Quote Billing Cadence And Customer Copy

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #180, `docs/rfc/2026-07-29-quote-billing-cadence-presentation.md`, and
`docs/feature-slices/2026-07-29-quote-billing-cadence-presentation.md`

Scope: Sales quote lines have explicit one-time, monthly, quarterly, or annual billing cadence with a
compatible monthly backfill for existing recurring lines. One shared presentation contract supplies
separate ex-VAT, VAT, and inc-VAT groups to Tech preview, public quote, Customer Portal, PDF, and quote
email. The draft editor exposes the existing version-owned customer copy, places introduction/scope
before prices and assumptions/exclusions/next steps after prices, and preserves copy and cadence when a
new revision is created.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan optimize:clear`,
and `php artisan knowledge:sync-docs --module=Sales --no-interaction`. Restart queue workers so quote
email jobs load the new presentation dependency. No permission seed, scheduler change, or frontend build
is required. The migration updates only an exact unchanged default quote email template; customized
templates must add the documented new variables manually if desired.

Risks: recurring prices must not appear as one-time totals; VAT must stay within the correct cadence;
legacy recurring lines must classify as monthly; sent/accepted versions must remain immutable; customer
copy must be escaped; and customized email templates must not be overwritten.

Automated verification: the complete Sales feature suite passes with 18 tests and 274 assertions. The
Customer Portal quote/contract suite passes with 2 tests and 55 assertions. PHP syntax and Blade cache
compilation pass. The focused Ticket-to-Sales PDF regression passes with 1 test and 19 assertions, and
the complete Ticket feature suite passes with 165 tests and 1,278 assertions.

Human checks:

- [ ] Create a draft with 5,200 NOK one-time and 551 NOK/month and confirm Tech preview shows separate
  groups with separate ex-VAT, VAT, and inc-VAT values.
- [ ] Add quarterly and annual lines and confirm their labels and totals use NOK/quarter and NOK/year.
- [ ] Save introduction, solution/scope, assumptions, alternatives, exclusions, and next steps; confirm
  the first pair appears before prices and the remaining text after prices.
- [ ] Compare Tech preview, public quote, authenticated Customer Portal, generated PDF, and received
  quote email and confirm grouping, terminology, totals, and copy agree.
- [ ] Send and revise the quote; confirm the sent version is immutable and the new draft retains all
  copy and cadence values.
- [ ] Open an existing quote without custom text and an existing recurring-contract line; confirm both
  remain readable and the recurring line appears as monthly.
- [ ] Check the editor and all customer surfaces at desktop and mobile widths.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-010 - Sales Opportunity Lost And Reopen Workflow

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #181 and `docs/rfc/2026-07-29-sales-opportunity-lost-workflow.md`

Scope: Sales has dedicated Tech UI and scoped API actions for marking an opportunity lost and
reopening it. The workflow requires a loss reason, preserves an optional internal note in the
activity audit, resets forecast and follow-up fields, removes only the matching future generated
Sales calendar event, keeps history and quotes, and reopens to a selected active status without
restoring the previous follow-up. Lost opportunities are hidden from the default pipeline but remain
available through search and the Lost status filter.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Sales --no-interaction`. No migration, backfill, queue
restart, scheduler change, permission seed, or frontend build is required.

Risks: a generic status update must not bypass the workflow; a manual, past, or unrelated calendar
event must not be deleted; lost, not-qualified, and no-quote outcomes must remain distinct; and
reopening must not silently recreate a follow-up.

Automated verification: the focused Tech and API workflow tests pass with 2 tests and 55 assertions;
the complete Sales feature suite passes with 18 tests and 274 assertions. Blade compilation and Git
diff checks pass.

Human checks:

- [ ] From an active opportunity with a future follow-up, open Mark as lost and confirm a reason is
  required while the internal note is optional.
- [ ] Submit the action and confirm status Lost, probability/weighted value zero, lost date/reason,
  cleared follow-up, and a new activity entry without losing prior activities or quotes.
- [ ] Confirm the matching future generated event disappears from Calendar, while a past, manually
  created, or unrelated event remains untouched.
- [ ] Confirm the opportunity is absent from the default Sales pipeline, but appears through search
  and the Lost status filter.
- [ ] Reopen it to an active status and confirm loss fields clear, probability/weighted value are
  recalculated, and no old follow-up is recreated.
- [ ] Confirm Not qualified and No quote allowed remain separate statuses and are not treated as Lost.
- [ ] Check the lost alert and both modals at desktop and mobile widths.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-009 - Documentation Template Selection

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #179 and `docs/rfc/2026-07-29-documentation-template-selection.md`

Scope: The Tech Documentation create flow keeps automatic selection when a category has exactly one
active template and presents a separate, required template choice when several active templates are
available. The selected active template determines the rendered fields and stored schema snapshot;
inactive and wrong-category template IDs are rejected.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Documentation --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, or frontend build is required.

Risks: the wrong schema must not be selected silently; crafted requests must not cross category or
inactive-template boundaries; and the established one-template flow must remain quick and backward
compatible.

Automated verification: the complete Documentation feature suite passes with 10 tests and 106
assertions. Blade compilation and Git diff checks pass.

Human checks:

- [ ] Choose a category with one active template and confirm the create form opens directly with its
  fields and saves successfully.
- [ ] Choose a category with two or more active templates and confirm a compact template step appears
  before the Documentation fields.
- [ ] Choose each template in turn and confirm the visible fields match that exact template.
- [ ] Save a record and confirm its category, template, entered data, and captured template snapshot
  are correct.
- [ ] Confirm inactive templates cannot be selected and changing a submitted template ID to another
  category produces a validation error.
- [ ] Check category and template selection at desktop and mobile widths.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-008 - Calendar Ownership Rollout Tests And Knowledge

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #142, plus
`docs/feature-slices/2026-07-29-calendar-ownership-rollout-tests-knowledge.md`

Scope: Regression coverage and operational documentation complete the Calendar ownership rollout.
The Calendar README, repository Knowledge article, TODO register, Feature Slice index, human-review
register, and public-safe website handoff describe the shipped metadata, badges, type indicators,
filters, privacy boundaries, and mobile behavior without claiming creator/participant `My events` or
event-level responsibility.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: documentation must match the real owner-based filter semantics; automated tests must not
replace manual visual/private-data review; and public website copy must remain unpublished until the
relevant human checks are complete.

Automated verification: the focused Calendar feature suite passes with 22 tests and 218 assertions.
The complete Dev Laravel suite passes with 921 tests and 7,158 assertions. Calendar Knowledge sync
processes one module, one chapter, and one article with no skips. Targeted Pint, PHP syntax, Blade
compilation, cache clearing, compiled-view permissions, and Git diff checks pass.

Human checks:

- [ ] Compare the Calendar README and Knowledge article with day, week, month, and list behavior and
  confirm the documented badges, groups, filters, empty state, and dense-month link are accurate.
- [ ] Confirm the documentation clearly states that `Only mine` uses Calendar ownership and that a
  creator/participant `My events` filter plus event-level responsibility remain out of scope.
- [ ] Confirm private/confidential masking and server-side visible-calendar permission boundaries
  are described accurately.
- [ ] Approve the public-safe website handoff before any customer-facing publication.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-007 - Calendar Mobile Readability And Dense Month Drill-Down

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #141, plus
`docs/feature-slices/2026-07-29-calendar-mobile-readability.md`

Scope: Month and week use a keyboard-focusable, touch-scrollable Calendar region with stable column
widths. Event title, time, owner, and type identities keep independent boundaries. Month renders five
events per day and then an accessible `+N more` link to the filtered day view. Nested controls do not
trigger the day-cell event-creation action.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, permission
seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: horizontal scrolling must remain discoverable and usable; badges and long titles must not
overlap; the `+N more` target must retain filters; and nested links must not open the create panel.
The final in-app browser attempt on 2026-07-29 was blocked before rendering by the known Dev
self-signed certificate (`ERR_CERT_AUTHORITY_INVALID`), so authenticated visual checks remain
manual.

Automated verification: dense-month coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It verifies the five-event limit, accessible `+N more`, filtered day link, focusable
scroll region, and minimum grid width.

Human checks:

- [ ] At desktop width, compare month and week with long titles, owner badges, and non-personal type
  badges; confirm there is no overlap or unexpected wrapping.
- [ ] At narrow/mobile width, keyboard-focus and touch-scroll month/week horizontally and confirm
  the day columns and event identity remain readable.
- [ ] Put at least seven events on one date, open month view, and confirm five rows plus `+2 more`.
  Open the link and confirm day view preserves active Calendar, ownership, search, and sort state.
- [ ] Confirm selecting `+N more` does not open the event-create panel, while selecting an empty part
  of the day cell still does.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-006 - Calendar Ownership Filters And Only Mine

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #140, plus
`docs/feature-slices/2026-07-29-calendar-ownership-filters.md`

Scope: The Tech Calendar sidebar groups visible calendars into Mine, People, Team, Shared/global,
Resources, and External/system. Backed groups expose server-derived counts. `Only mine` selects only
calendars owned by the signed-in user through `owner_type` and `owner_id`, takes priority over group
selections, and remains independent from event creator/participant data. Ownership and explicit
Calendar filters intersect, persist across navigation/search/availability actions, and expose clear
and empty states.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: filter parameters must never broaden visible calendars; an empty intersection must not fall
back to all events; `Only mine` must not include a team event merely because the viewer created it;
and existing date, Calendar, search, sort, and availability state must remain compatible.

Automated verification: ownership-filter coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It verifies owner-versus-creator semantics, combined groups, explicit Calendar
intersection, unauthorized hidden calendars, backed controls, and the empty state.

Human checks:

- [ ] Confirm sidebar groups and counts match visible personal, other-person, team, shared/company,
  resource, and external/system Calendars for the signed-in technician.
- [ ] Enable `Only mine` and confirm only the technician-owned personal Calendar remains, while an
  event created by that technician on a team Calendar is excluded.
- [ ] Combine Team and Resources, then combine a group with individual Calendar checkboxes; confirm
  both conditions must match and an empty intersection shows the explicit empty state.
- [ ] Switch views, navigate dates, search, sort, use Find Time, and open a dense-month day; confirm
  ownership state persists. Use Clear ownership filters and confirm ordinary Calendar/search state
  remains.
- [ ] Manipulate a URL with a hidden Calendar ID and confirm its name and events remain absent.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-005 - Calendar Non-Personal Type Indicators

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #139, plus
`docs/feature-slices/2026-07-29-calendar-type-indicators.md`

Scope: Day, week, month, and list show a separate compact type badge for non-personal Calendars.
Shared, team, company/global, absence, shift, resource, system, external, and fallback types use
stable visible abbreviations with full accessible labels. Personal events avoid a redundant type
badge. Owner, type, and Calendar color remain separate signals on normal and masked events.

Deployment actions: deploy the code and run `php artisan optimize:clear`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or integration action is required.

Risks: abbreviations must remain understandable through their full accessible labels; extra badges
must not crowd event content; type must come from the server metadata contract; and a masked private
event must never expose its real title or other details through badge text or tooltip.

Automated verification: type-indicator coverage passes inside the 22-test, 218-assertion Calendar
feature suite. It covers representative shared, team, company/global, resource, and external badges,
accessible labels, compact text, and private resource masking.

Human checks:

- [ ] Compare shared, team, company/global, absence/shift, resource, and external/system events in all
  four Calendar views and confirm the short type marker is consistent and understandable.
- [ ] Hover or use assistive technology and confirm each marker exposes the complete Calendar type
  without relying on color.
- [ ] Confirm a personal event shows its owner badge without a redundant type badge.
- [ ] As a technician without private-detail access, confirm a private non-personal event retains
  only `Busy`, time, safe owner/color/type context, and no real event details.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-004 - Calendar Owner Badges And Accessible Color Identity

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134 and #138, plus
`docs/feature-slices/2026-07-29-calendar-owner-badges-accessible-color.md`

Scope: The Tech Calendar day, week, month, and list views use one shared compact owner badge. It
combines owner initials or a stable type fallback, a bordered Calendar-color swatch, and an
accessible full owner/type label. Event title containers truncate independently from badge and time.
Month and week retain readable widths on narrow screens through horizontal scrolling, and list
Calendar identity combines name with the same swatch rather than depending on background color.
Private/confidential blocks retain only safe owner/color/type/time signals and masked `Busy` detail.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or external synchronization action
is required.

Risks: compact badges must remain readable without crowding event time/title; arbitrary Calendar
colors need a visible border and text alternative; long content must not overlap adjacent events;
month/week horizontal overflow must remain usable on mobile; and private/confidential badge context
must never include masked event details. The completed #139-#141 type, filter, and mobile slices
reuse the same metadata contract without duplicating owner derivation in Blade or JavaScript.

Automated verification: the focused Calendar feature suite passes with 19 tests and 174 assertions.
It covers all four views, owner initials, color swatches, accessible owner/type labels, title layout,
list Calendar identity, and private-detail masking. The complete Dev Laravel suite passes with 918
tests and 7,114 assertions. Calendar Knowledge synchronization processes one chapter and one article
with no skips. Blade view caching, cache clearing, targeted Pint, and Git diff checks pass.

Human checks:

- [ ] Open month view with events from at least two technician calendars and one ownerless
  team/shared/resource Calendar. Confirm ownership is recognizable within one second from the short
  badge text plus swatch.
- [ ] Hover or inspect the badge with assistive technology and confirm the full owner identity and
  Calendar type are available without relying on color.
- [ ] Compare the same events in day, week, month, and list. Confirm the badge identity and Calendar
  color remain consistent in every view.
- [ ] Use long event titles and adjacent events. Confirm badge, time, and title do not overlap and
  that the title truncates cleanly.
- [ ] At a narrow mobile width, verify day/list badge readability and horizontal month/week
  navigation without compressed or overlapping badges.
- [ ] As a technician without private-detail access, open a private/confidential event in all four
  views. Confirm the badge, color, type, time, and `Busy` remain visible, while real title,
  description, location, participants, meeting link, and integration details remain absent.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-003 - Calendar Ownership View Metadata And Private Single-Event API

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issues #134, #136, and #137, plus
`docs/feature-slices/2026-07-29-calendar-ownership-view-metadata.md`

Scope: Calendar derives stable owner identity, initials/badge, color, normalized type, ownership
group, and viewer-owned state from the calendar container rather than the event creator. The
contract is available to visible Calendar list, range event, recurring occurrence, and single-event
API consumers while retaining the existing overlay badge key. Visibility remains server-scoped by
`CalendarOverlayQuery`. The single-event API now applies `CalendarVisibility` before exposing
private/confidential title, description, location, meeting URL, participants, creator, or external
integration identifiers.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, frontend build, or external synchronization action
is required.

Risks: creator data must not be mistaken for ownership; ownerless team/shared calendars need stable
fallbacks; metadata must not expand the visible calendar set; private single-event responses must
not leak details or participant/integration identifiers; owners and explicitly permitted viewers
must retain legitimate private-detail access; and the completed #138-#141 UI/filter work must
consume this contract rather than reimplement ownership in Blade or JavaScript.

Automated verification: the focused Calendar feature suite passes with 18 tests and 127 assertions.
It covers owner-versus-creator semantics, viewer groups, ownerless team fallback, hidden-calendar
boundaries, Calendar and range API metadata, private single-event masking, and owner detail access.
The complete Dev Laravel suite passes with 917 tests and 7,067 assertions. Calendar Knowledge
synchronization processed one chapter and one article with no skips. Cache clearing, PHP syntax,
targeted Pint, and Git diff checks pass.

Human checks:

- [ ] Call `GET /api/v1/calendars` as a technician and confirm the personal calendar reports the
  technician's owner label/initials, `calendar_type: personal`, `ownership_group: mine`, and
  `is_owned_by_viewer: true`.
- [ ] View a visible calendar owned by another technician and confirm the owner remains that
  calendar owner even when the event creator is someone else; the group must be `people`.
- [ ] Inspect ownerless team, shared/company, resource, and system/external calendars and confirm
  each has a readable calendar-name fallback, stable short badge, and the documented group.
- [ ] Confirm a hidden calendar without owner/default/access visibility is absent from Calendar
  list and range event responses.
- [ ] As another technician, request a private/confidential event through both range and
  single-event endpoints. Confirm the response keeps time plus safe owner/color/type signals but
  shows `Busy`, null detail/integration fields, and no participants.
- [ ] Request the same private event as the calendar owner or an explicitly authorized private
  viewer and confirm legitimate details and participants remain available.
- [ ] Open day, week, month, and list Calendar views and confirm the existing ownership badges and
  event rendering still consume the metadata contract. Detailed badge layout and accessibility are
  reviewed separately in `HR-2026-07-29-004`.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-002 - Ticket API Portal Publication And Idempotent Customer Completion

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #194, `docs/rfc/2026-07-29-ticket-api-customer-completion-flow.md`,
`docs/adr/2026-07-29-ticket-api-message-idempotency.md`, and the three 2026-07-29 Ticket API
Feature Slices

Scope: Trusted API coordinators can publish an eligible client Ticket through the same lock-safe,
one-way action as the technician UI, then create one real public technician customer reply through
the normal Ticket message, outbound email, portal notification, relationship sync, event,
first-response, and workflow path. `reply_intent: send_solution` records normal solution facts so
the coordinator can inspect decisions, perform an allowed Resolved transition, and call the existing
Close action. Dedicated token abilities and existing user/domain permissions both apply. Ticket
message idempotency is enforced by a Ticket/key database unique index and payload fingerprint;
matching retries return the original result, while changed actor/payload and soft-deleted results
return HTTP 409. Inbound `external-messages` behavior remains isolated.

Deployment actions: deploy the code, run `php artisan migrate --force`, `php artisan optimize:clear`,
`php artisan l5-swagger:generate`, `php artisan knowledge:sync-docs --module=Ticket --no-interaction`,
and `php artisan knowledge:sync-docs --module=Integration --no-interaction`. Migration
`2026_07_29_120000_add_api_idempotency_to_ticket_messages` already ran on Dev in batch 54. Existing
API tokens do not receive the two new abilities automatically. No frontend build, permission seed,
scheduler change, or new queue worker is required; the existing Ticket reply queue worker must run
where actual customer email is enabled.

Risks: a bad retry boundary could send duplicate customer email or notification; an over-broad
token could expose customer-facing side effects; a cross-client or inactive Contact must never be
used; publication must remain one-way; matching retries after workflow changes must remain safe;
and `external-messages` must not become a solution or outbound technician-reply shortcut.

Automated verification: the focused Ticket customer-completion API suite passes with 4 tests and
53 assertions. TicketModuleTest, PortalTicketTest, and TicketWorkflowV3Test pass with 146 tests and
1,156 assertions. The suite verifies a single queued reply job and uses fakes, so no real customer
email was sent. Migration/status, PHP syntax, targeted Pint, route discovery, OpenAPI generation,
and the complete publish/reply/decisions/Resolved/Close API sequence pass on Dev. The Integration
API Management suite also passes with 43 tests and 343 assertions. Knowledge synchronization
processed one Ticket chapter with 11 articles and one Integration chapter with 5 articles, with no
skips. The complete Dev Laravel suite passes with 915 tests and 7,014 assertions.

Human checks:

- [ ] Create or update a least-privilege Dev API token with `tickets.portal.publish`,
  `tickets.reply_customer`, `tickets.workflow.read`, and `tickets.actions`; confirm a token missing
  each relevant ability receives 403 and the authenticated user also needs the normal domain
  permissions.
- [ ] Using a clearly internal test customer/contact, publish an Unpublished Ticket and confirm the
  first response reports `published_now: true`, a repeat reports `false`, and only one publication
  event and portal notification exist. Confirm `portal_visible: false` cannot unpublish it.
- [ ] Send a safe customer reply with one idempotency key while outbound delivery is intercepted or
  directed only to the internal test mailbox; confirm one public user-authored message and one email
  delivery/queue record, then repeat the identical request and confirm no duplicate side effect.
- [ ] Reuse that key with changed body or another user and confirm HTTP 409. Delete a separate test
  reply and confirm its old key remains reserved with HTTP 409.
- [ ] Try an Unpublished Ticket, internal Ticket, inactive/missing-email Contact, cross-client
  Contact, closed Ticket, and workflow-blocked action; confirm each is rejected without message,
  email, notification, event, or workflow changes.
- [ ] Send `reply_intent: send_solution`, read workflow decisions, perform only the reported allowed
  Resolved transition, then close as completed. Confirm the audit/history and lifecycle timestamps.
- [ ] Sync one inbound `external-messages` test payload containing attempted solution metadata and
  confirm it retains external authorship, strips workflow-driving metadata, and sends no normal
  technician reply email.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-29-001 - Published Default For Manually Created Client Tickets

Status: Pending
Added: 2026-07-29
Environment: Dev
Related: GitHub issue #191, `docs/rfc/2026-07-28-manual-client-ticket-published-default.md`,
`docs/rfc/2026-07-04-customer-portal-foundation.md`, and
`docs/feature-slices/2026-07-04-customer-portal-ticket-workflow.md`

Scope: Published is now the clean-install, missing-setting, and invalid-setting fallback for new
manually created client Tickets. A valid administrator choice of Published or Unpublished remains
authoritative, and the visible Ticket create control preselects that setting while preserving a
technician override after validation errors. Published client Tickets use the existing visibility
timestamp, actor, audit event, and Customer Portal notification path. Internal Tickets without a
Client remain portal-hidden even when Published is submitted. Existing Tickets are not changed, the
initial description remains an internal note, and no customer-reply email job is added.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run
`php artisan knowledge:sync-docs --module=Ticket --no-interaction`. No migration, backfill,
permission seed, queue restart, scheduler change, or frontend build is required. Valid stored Ticket
portal-policy settings must not be replaced during deployment.

Risks: new client Tickets can expose customer-sensitive content immediately when Published is the
effective default; administrators and technicians must notice the visible choice. A manipulated
request must not publish an internal Ticket; a validation failure must not discard an Unpublished
override; existing hidden Tickets must remain hidden; and the default change must not create a
public initial message or queue `SendTicketReplyEmail`. The existing `portal_ticket_created`
notification continues to honor recipients' already configured notification channels.

Automated verification: the focused Ticket portal suite passes with 10 tests and 94 assertions. The
complete Ticket feature suite passes with 158 tests and 1211 assertions, and the complete Customer
Portal feature suite passes with 20 tests and 229 assertions. Pint passes for the two touched PHP
files. PHP syntax, `git diff --check`, Blade cache compilation, and compiled-view group-write
permissions pass. Repository Knowledge synchronization processed one Ticket chapter and eleven
articles without skips. The Ticket create and Ticket Settings URLs return the expected unauthenticated
login redirect through the local Dev HTTPS virtual host.

Human checks:

- [ ] With no Ticket portal-policy row on a disposable environment, open Ticket create and confirm
  Published is selected while both Published and Unpublished remain visible.
- [ ] In Ticket Settings, save Unpublished and confirm a new manual Ticket form preselects
  Unpublished. Save Published again and confirm a new form preselects Published.
- [ ] Trigger a validation error after choosing Unpublished and confirm the form returns with
  Unpublished still selected.
- [ ] Create a client Ticket with the Published default and confirm the customer can see it, the
  Ticket records the correct portal visibility timestamp and technician, and the established portal
  notification appears according to the customer's existing channel preferences.
- [ ] Confirm the Published Ticket's initial description remains an internal note and that creation
  does not send or queue a separate customer-reply email.
- [ ] Override one new client Ticket to Unpublished and confirm it remains absent from the Customer
  Portal, emits no portal-publish notification, and blocks Reply to contact until later publication.
- [ ] Create an internal Ticket without a Client while Published is selected and confirm it remains
  portal-hidden and no customer notification is emitted.
- [ ] Confirm an existing Unpublished Ticket remains hidden after deployment and still supports the
  established one-way Publish action.
- [ ] Check Ticket Settings and Ticket create at desktop and narrow/mobile widths and confirm the
  visibility control and explanatory text are conspicuous and readable.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-28-005 - Ticket Internal Note Solution Toggle

Status: Pending
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #190, `docs/rfc/2026-07-28-ticket-internal-note-solution-toggle.md`, and
`docs/rfc/2026-06-03-ticket-solution-policy.md`

Scope: The Ticket Add message composer now exposes only Reply to contact and Internal note as
message types. When Ticket solution policy permits it, Internal note shows a compact Mark as
solution switch that is off by default. Both ordinary and solution-marked entries persist as
internal notes; a marked note reuses the established solution metadata and workflow action while
remaining customer-hidden. Notify technician stays available in both states. The server forces
internal visibility, rechecks solution policy, ignores the switch for customer replies, and retains
a policy-checked legacy `internal_solution` input alias for stale forms without rendering it.
Historical messages and timeline Mark as solution actions remain compatible.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Ticket --no-interaction`. No migration, backfill, permission seed,
queue restart, scheduler change, or frontend build is required. Actual technician-notification
email delivery continues to depend on the existing configured queue worker and Ticket email account.

Risks: a manipulated switch or legacy alias must not bypass disabled policy; a solution-marked note
must never become public or send customer email; switching message type must not alter customer
Reply intent; Notify technician must remain available and preserved; and current workflow solution
requirements, automatic triggers, historical messages, and timeline solution actions must not
regress.

Automated verification: the complete Ticket feature suite passes with 155 tests and 1183 assertions.
The focused composer, solution policy, technician notification, customer-email isolation, legacy
alias, historical timeline action, and Workflow v3 run passes with 9 tests and 68 assertions. Pint
passes for the three touched PHP files. PHP syntax, `git diff --check`, Blade cache compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Ticket chapter and eleven articles without skips. The Ticket show URL returns the expected
unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [ ] Open a Ticket where both actions are available and confirm Message type contains Reply to
  contact and Internal note, with no separate Internal solution option.
- [ ] Select Internal note and confirm Mark as solution is visible, off by default, and displayed
  together with Notify technician at desktop and narrow/mobile widths.
- [ ] Save an ordinary Internal note and confirm it stays internal, is not marked as solution, and
  does not satisfy a workflow solution requirement by itself.
- [ ] Enable Mark as solution, select a technician, and save. Confirm the note stays internal, is
  visibly marked as the selected solution, satisfies the expected workflow requirement or trigger,
  and keeps the technician notification. Confirm no customer email or Customer Portal message is
  created; record actual technician inbox receipt separately if email delivery is tested.
- [ ] Disable internal solution notes in Ticket Settings and confirm the switch disappears. Verify a
  manipulated request cannot mark an Internal note as the solution, then restore the setting.
- [ ] Confirm a public Reply to contact with Send solution still follows the ordinary customer email
  and workflow path, without being affected by the internal-note switch.
- [ ] On an existing Ticket, use the timeline Mark as solution action for an eligible historical
  Internal note and confirm the established behavior still works.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-28-004 - Client Summary Layout And Notes Autosave

Status: Pending
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #189 and `docs/rfc/2026-07-28-client-summary-notes-autosave.md`

Scope: The Client Summary card now presents Client number and the existing short metadata in a
responsive Bootstrap grid with three columns on wide screens, two on medium screens, and one on
small screens. Notes use the full card width. Users with `client.update` receive a debounced
Livewire textarea that saves only Notes, reports Saving while unconfirmed, and shows Saved only
after successful server persistence. The save path rechecks permission on every request, preserves
text on handled failure, stores a blank value as null, and renders read-only Notes for other users.
The existing full Client settings workflow remains available and compatible.

Deployment actions: deploy the code, run `php artisan optimize:clear`, and run `php artisan
knowledge:sync-docs --module=Clients --no-interaction`. No migration, backfill, permission seed,
queue restart, scheduler change, or frontend build is required.

Risks: a manipulated Livewire request must not bypass `client.update`; Saved must never appear for a
failed or still-pending write; concurrent editors continue to use last-confirmed-save-wins behavior;
and the responsive layout must preserve status, billing email, optional N-able RMM mapping, gear
action, and the Client workspace below the Summary card.

Automated verification: the complete Clients feature suite passes with 32 tests and 293 assertions.
The focused Summary and Notes autosave runs pass with 5 tests and 69 assertions, including update,
blank/null, read-only, forced unauthorized save, deleted-Client failure, responsive markup, and
existing settings-link coverage. Pint passes for both new PHP files. PHP syntax, `git diff --check`,
Blade cache compilation, and compiled-view group-write permissions pass. Repository Knowledge
synchronization processed one Clients chapter and one article without skips. The Client show URL
returns the expected unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [ ] Open a Client with Client number, organization number, billing email, format, and active RMM
  mapping; confirm all Summary values, status, RMM state, and the gear action remain visible.
- [ ] At wide, medium, and narrow/mobile widths, confirm the short metadata changes from three to
  two to one column and Notes remains full width without crowding the Client workspace.
- [ ] As a user with Client update access, edit Notes and confirm Saving appears while the text is
  unconfirmed, Saved appears only after completion, and a page reload shows the persisted text.
- [ ] Clear Notes completely and confirm the empty value remains after reload; then enter a new
  value and confirm the old error or status does not remain stale.
- [ ] As a user without Client update access, confirm Notes is read-only and no textarea or save
  state is exposed. Attempting a manipulated update must not change the Client.
- [ ] Open the existing Client settings form, update Notes there, save, and confirm the Summary
  component shows the new value so both edit workflows remain compatible.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-28-003 - Client Workspace Tickets Tab

Status: Pending
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #188 and `docs/rfc/2026-07-28-client-workspace-tickets-tab.md`

Scope: The Client profile now includes a Tickets tab for technicians with `ticket.view`. Ticket
owns the access-aware query, while Clients owns the workspace placement and compact Bootstrap
table. The tab shows all non-deleted open and closed Tickets linked directly to the selected Client,
uses one result set for the count and rows, links to the existing Ticket detail page, and keeps the
required Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts, Signals, Custom Fields order.

Deployment actions: deploy the code, run `php artisan optimize:clear`, run `php artisan
knowledge:sync-docs --module=Clients --no-interaction`, and run `php artisan knowledge:sync-docs
--module=Ticket --no-interaction`. No migration, backfill, permission seed, queue restart, scheduler
change, or frontend build is required.

Risks: Client access must not disclose Ticket data without `ticket.view`; the count must match the
rendered rows; open and closed Client Tickets must remain visible while soft-deleted and other-Client
Tickets remain absent; and tab reordering must not break the existing Client workspace panes.

Automated verification: the complete Client feature file and all Ticket feature tests pass with 182
tests and 1424 assertions. The focused access, scoping, ordering, count, link, and soft-delete run
passes with 4 tests and 60 assertions. PHP syntax, `git diff --check`, Blade cache compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Clients article and eleven Ticket articles without skips. The Client Tickets URL returns the
expected unauthenticated login redirect through the local Dev HTTPS virtual host.

Human checks:

- [ ] As a technician with `client.view` and `ticket.view`, open a Client with at least one open and
  one closed Ticket and confirm the Tickets badge matches the two displayed rows.
- [ ] Confirm the workspace order is Assets, Sites, Contacts, Tickets, Tasks, Time, Contracts,
  Signals, and Custom Fields where Custom Fields is configured.
- [ ] Confirm each row shows Ticket key, subject, status, priority, queue, owner, and updated time,
  and that both the key and row open the correct existing Ticket detail page.
- [ ] Confirm a Ticket from another Client and a soft-deleted Ticket do not appear for the selected
  Client.
- [ ] As a technician with `client.view` but without `ticket.view`, confirm the Tickets tab, count,
  and Ticket subjects are all absent.
- [ ] Check desktop and narrow/mobile widths and confirm the tab strip and compact table remain
  usable without breaking the other Client tabs.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-28-002 - Contact Portal Invitation Create Override

Status: Pending
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #185, `docs/rfc/2026-07-04-customer-portal-foundation.md`,
`docs/feature-slices/2026-07-28-contact-portal-invitation-override.md`, and follow-up from
`HR-2026-07-15-001`

Scope: Contact Settings now owns a safe default-off option for selecting `Send customer portal
invitation` on Contact create. A user with `customer_portal.invite` can override that initial state
for one create action. Contact and its Client/Site relations are saved transactionally before the
existing CustomerPortal action validates scope, identity, active access, and pending invitations,
then records audit and queues one Viewer invitation. Ordinary Contact edit never exposes or resends
the option.

Deployment actions: deploy the code; run `php artisan optimize:clear`; run `php artisan
knowledge:sync-docs --module=Contact --no-interaction`; and run `php artisan knowledge:sync-docs
--module=CustomerPortal --no-interaction`. No migration, permission seed, queue restart, scheduler
change, or frontend build is required. Actual invitation email delivery continues to depend on the
existing configured queue worker and outbound system email setup.

Risks: an unintended global-on default could send invitations unless the per-Contact choice remains
clear; users without `customer_portal.invite` must not reveal or force the option; ordinary edits
must not resend; Contact and invitation writes must roll back together on validation failure; and
email identity, active access, duplicate invitation, Client, and Site guards must stay authoritative.

Automated verification: the complete Contact and CustomerPortal foundation suites pass with 49
tests and 336 assertions. The focused default-on/off, edit, permission, and Site-scope regressions
pass with 5 tests and 29 assertions. Pint, PHP syntax, `git diff --check`, Blade compilation, and
compiled-view group-write permissions pass. Repository Knowledge synchronization processed one
Contact and one CustomerPortal chapter/article without skips. HTTPS smoke tests for Contact create and Contact
Settings return the expected unauthenticated login redirects. The complete Laravel suite was not
run for this focused slice.

Human checks:

- [ ] Open Contact Settings and confirm `Send customer portal invitation by default` is clear,
  saves both on and off, and remains off when no explicit setting has been stored.
- [ ] Enable the global default, open Contact create as a user with `customer_portal.invite`, and
  confirm the create-only switch starts on. Turn it off, save a valid client Contact, and confirm no
  invitation is created or sent.
- [ ] Disable the global default, turn the create switch on for a valid Contact with email and an
  active Client/Site, and confirm exactly one pending Viewer invitation uses that scope.
- [ ] Confirm the queued invitation email uses the ordinary Customer Portal template and reaches
  the intended test inbox; record inbox receipt separately from the application queue result.
- [ ] Try missing email, inactive Client, wrong Site scope, and existing active portal access;
  confirm each is blocked without leaving a partial new Contact or duplicate access.
- [ ] Open Contact create as a user without `customer_portal.invite`; confirm the switch is absent
  and a manipulated Livewire request cannot create an invitation.
- [ ] Edit a Contact while the global default is on; confirm the switch is absent and saving does
  not create or resend an invitation.
- [ ] Check Contact Settings and Contact create at desktop and mobile widths.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-28-001 - Booking Hours And Technician Routing

Status: Pending
Added: 2026-07-28
Environment: Dev
Related: GitHub issue #184, `docs/rfc/2026-07-04-online-booking-calendar-availability.md`,
`docs/feature-slices/2026-07-28-booking-hours-and-technician-routing.md`, and follow-up from
`HR-2026-07-15-001`

Scope: Booking service settings can limit public availability to an optional daily opening window,
use company or technician-profile working hours, and route to one fixed technician, an automatic
eligible pool, or a customer-selected eligible technician. Automatic mode exposes only the union of
available times, persists one concrete technician internally, and may safely reroute to another
eligible free technician during staff confirmation. Calendar remains responsible for personal
calendars and conflict checks. Booking create/edit use the shared Page Header Back action, and the
honeypot field is explained under advanced spam protection.

Deployment actions: deploy the code; run `php artisan migrate --force`; run `php artisan
optimize:clear`; run `php artisan knowledge:sync-docs --module=Booking --no-interaction`; and run
`php artisan knowledge:sync-docs --module=Calendar --no-interaction`. No permission seed, queue
restart, scheduler change, or frontend build is required.

Risks: automatic mode must not expose eligible technician identities; customer choice must stay
inside the configured active pool; the public opening window must intersect rather than replace the
selected working-hours policy; concurrent requests may select the same unreserved time until staff
confirmation; and confirmation must never create an event on a calendar that has become busy.

Automated verification: the Booking and Calendar feature suites pass with 31 tests and 171
assertions. The complete Knowledge article feature suite passes with 39 tests and 355 assertions,
including the new Booking repository-documentation sync regression. Migration
`2026_07_28_120000_add_routing_and_opening_hours_to_booking_service_settings` ran on Dev in batch
53. Pint, PHP syntax, `git diff --check`, Blade compilation, and the public `/booking` HTTPS smoke
test pass. Repository Knowledge synchronization processed one Booking chapter/article and one
Calendar chapter/article without skips. The focused suites were run on Dev; the complete Laravel
suite was not run for this slice.

Human checks:

- [ ] Open Booking create and edit and confirm Back is in the shared Page Header while Save remains
  at the bottom of the form.
- [ ] Confirm the spam field is under Advanced spam protection with a plain-language explanation.
- [ ] Configure a fixed-technician service with a 10:00-15:00 public window and company hours;
  confirm public times stay inside both limits and existing Calendar conflicts disappear.
- [ ] Switch to technician-profile hours and confirm a disabled day or shorter profile day changes
  the public slots without changing company Calendar settings.
- [ ] Configure automatic routing with two eligible technicians whose free periods differ; confirm
  the public page shows the combined time union without either technician name or identifier.
- [ ] Submit an automatic request and confirm one eligible technician is stored internally. Make
  that technician busy before confirmation and confirm Booking uses another eligible free
  technician; make every eligible technician busy and confirm no Calendar event is created.
- [ ] Configure customer choice and confirm only active configured technicians are shown. Select
  each technician and confirm the times reflect that technician's availability.
- [ ] Tamper with the submitted customer-choice technician ID and confirm the request is rejected.
- [ ] Confirm a fixed-technician request still follows the existing received, staff-confirmed,
  Calendar-event, and customer-email workflow.
- [ ] Check Booking admin create/edit and the public booking page at desktop and mobile widths.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-27-001 - AI Model Execution Contract And Usage Ledger

Status: Pending
Added: 2026-07-27
Environment: Dev
Related: `docs/rfc/2026-07-27-ai-model-usage-and-cost-telemetry.md`,
`docs/adr/2026-07-27-integration-owned-ai-model-execution-telemetry-boundary.md`, and
`docs/feature-slices/2026-07-27-ai-model-execution-usage-ledger.md`

Scope: Integration-owned model-attempt execution boundary; one sanitized event per actual outbound
request; logical execution and ordered fallback correlation; normalized OpenAI-compatible,
OpenRouter-compatible, and Ollama usage; provider-returned model/request/finish metadata;
provider-reported cost where available; chat/actor/source context; payload exclusions; and explicit
non-blocking telemetry-write health signaling.

Deployment actions: deploy the code; run `php artisan migrate --force`; run `php artisan
optimize:clear`; and run `php artisan knowledge:sync-docs --module=Integration --no-interaction`.
No permission seed, queue restart, scheduler entry, frontend build, rate configuration, or Admin UI
is introduced by this slice. Migration
`2026_07_27_120000_create_ai_model_usage_events_table` ran on Dev in batch 52.

Risks: missing provider usage must not appear as zero; fallback may create several legitimate
attempts for one product action; raw payload or provider errors must not enter the ledger; provider
aliases can differ from the requested model; actor metadata must not become employee ranking; and a
telemetry persistence failure can create a visible coverage gap even though the AI response remains
available.

Automated verification: the focused telemetry suite passes with 8 tests and 95 assertions. It
covers OpenRouter cost, OpenAI Responses/Chat normalization, Ollama timing, explicit zero versus
missing usage, three-step endpoint fallback, chat context, payload exclusions, and telemetry-write
failure behavior. The complete affected Integration, Signal, Marketing, Lead Intelligence, Task,
and Ticket run passes with 307 tests and 2,600 assertions. Existing focused responder compatibility
passes with 13 tests and 105 assertions. PHP syntax and Pint pass. The migration preview, Dev batch
52 migration, actual 43-column InnoDB schema, foreign keys, uniqueness constraint, and reporting
indexes are verified. Repository Knowledge synchronization processed one Integration chapter and
five articles with no skips. The complete Dev Laravel suite passes with 880 tests and 6,720
assertions. The HTTPS route returns the expected login redirect when
unauthenticated. Authenticated visual smoke testing remains open because the known Dev certificate
is rejected by the in-app browser.

Human checks:

- [ ] Open `/tech/knowledge/ai` on Dev in a browser that trusts the Dev certificate and confirm the
  existing chat workspace, agent selection, chat history, and message controls load normally.
- [ ] Send one non-sensitive internal test prompt through an active Dev agent and confirm the normal
  model answer is stored once without a duplicate assistant message.
- [ ] Inspect the resulting `ai_model_usage_events` row and confirm provider, agent, requested/actual
  model, endpoint, feature, attempt number, status, duration, and reported token fields match the
  controlled request.
- [ ] Confirm that row contains no prompt, answer, source-record text, credential, authorization
  header, or raw provider error body.
- [ ] If the selected provider reports cost, compare the stored provider-reported amount/currency
  with its provider usage response or dashboard. Record unavailable fields as unavailable, not zero.
- [ ] Confirm no new telemetry report, rate editor, budget control, Client charge, or employee
  leaderboard is exposed by this foundation slice.
- [ ] If a safe endpoint-fallback model is available, confirm each failed/successful attempt has the
  same logical execution ID and increasing attempt numbers.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-24-001 - Web Push Channel And Internal-User Device Foundation

Status: In Review
Added: 2026-07-24
Environment: Dev
Planned final verification environment: Production beta
Related: `docs/rfc/2026-07-23-web-push-inbound-email-alerts.md`,
`docs/adr/2026-07-23-notification-owned-web-push-channel.md`, and
`docs/feature-slices/2026-07-24-web-push-channel-device-foundation.md`

Scope: globally guarded standards-based Web Push transport; stable VAPID configuration; the existing
shared service worker; explicit current-device registration; privacy-safe own-device inventory;
current-device generic self-test; user and permission-guarded administrator revocation; durable
secret-free subscription lifecycle audit; automatic disabled-user cleanup; and default-off
Notification preference fields that do not expose unsupported business-event controls.

Deployment actions: install the locked Composer dependencies; configure stable
`VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, and `VAPID_SUBJECT`; keep production
`WEBPUSH_ENABLED=false`; run `php artisan migrate --force`; run `php artisan optimize:clear`;
restart queue workers; and verify HTTPS plus the external scheduler runner. On Dev, migration batch
51 ran, stable Dev VAPID material was generated without displaying it, the switch was enabled, and
sanitized readiness reports Ready. No production inbound-email Web Push event is enabled by this
slice.

Risks: browser permission cannot be recovered by Nexum after the user denies it; endpoint and key
material must never appear in UI, logs, or audit records; lost VAPID keys invalidate subscriptions;
service-worker regressions could affect existing offline fallback; and user offboarding must remove
every registered device without crossing user scope. A persistent Dev queue worker and external
scheduler runner could not be verified on 2026-07-24; generic self-tests remain queued until the
worker is configured.

Automated verification: the focused Web Push/Notification run passes with 39 tests and 202
assertions; affected UserManagement admin/API tests pass with 15 tests and 107 assertions; the
Web Push foundation alone passes with 19 tests and 131 assertions; the Node service-worker smoke
test passes same-origin and hostile-target fallback checks; PHP syntax, Pint, Blade compilation,
route registration, migration preview, migration batch 51, HTTPS `/sw.js`, sanitized readiness,
and Web Push channel container resolution pass. Composer audit reports no advisory for any of the
five newly locked Web Push dependency packages; the wider existing lock still reports advisories
for 15 packages and 8 abandoned packages. The full suite completed with 871 passing tests
and 6,616 assertions plus one order-sensitive Risk API list assertion; the isolated Risk suite then
passed with 9 tests and 63 assertions. The unrelated Risk list assertion remains a known flaky test,
not a Web Push regression.

Review notes:

- 2026-07-25, Svein Tore, Dev: selecting Enable on this device failed because the browser could not
  fetch `https://nexum-psa.local/sw.js` for Service Worker registration.
- Server verification returned HTTP 200 with `Content-Type: text/javascript`; the served file
  matched `public/sw.js` and passed Node syntax checks.
- The active self-signed Dev certificate has no Subject Alternative Name for `nexum-psa.local`.
  Service Worker registration requires a trusted secure context, so Dev needs a trusted certificate
  with the correct SAN before this check can be repeated.
- 2026-07-25, Svein Tore: accepted deferring the Service Worker and Web Push browser check from Dev
  to the real production beta domain, which already has trusted SSL. The Dev certificate will not
  be treated as a Slice 1 code defect. This deferral is not approval; all relevant browser,
  installation, privacy, and delivery checks remain open until production-beta verification.

Human checks:

- [ ] Configure/verify a persistent Dev queue worker and the external once-per-minute scheduler
  runner before testing delivery.
- [ ] Confirm the preferences page never prompts for browser permission until Enable is clicked.
- [ ] Confirm global-disabled, incomplete-VAPID, unsupported, insecure-context, denied,
  unsubscribed, and subscribed states are understandable.
- [ ] Re-test Service Worker registration after `nexum-psa.local` uses a trusted certificate with a
  matching Subject Alternative Name.
- [ ] Register one Chrome/Edge device, receive the generic test, click it, and confirm Nexum focuses
  an existing authorized window or opens Notification Preferences.
- [ ] Confirm the own-device list shows only generated label, browser/platform family, registration
  time, and last-seen time, with no endpoint or key material.
- [ ] Register a second device, revoke it from the first device, and confirm it no longer receives a
  test; then re-register it.
- [ ] As an administrator with `notification.manage_channels`, list and revoke another internal
  user's device without seeing secrets.
- [ ] As an ordinary user, confirm the administrator device routes are hidden or denied.
- [ ] Sign out and confirm the registered device remains subscribed; sign back in before opening a
  test target.
- [ ] Disable a test user and confirm every owned subscription is removed with a secret-free audit
  record.
- [ ] Confirm the existing PWA install, ordinary navigation, static-asset caching, and offline
  fallback still work after the shared service-worker update.
- [ ] Repeat supported checks in Firefox, Safari/macOS, and an installed iOS/iPadOS Home Screen PWA
  where those devices are available.

### HR-2026-07-22-001 - CloudFactory Versioned Legal Documents And Portal Licence Ordering

Status: Pending
Added: 2026-07-22
Environment: Dev
Related: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`,
`docs/adr/2026-07-22-versioned-legal-documents-and-transaction-acceptance.md`, and
`docs/feature-slices/2026-07-22-cloudfactory-legal-documents-and-portal-ordering.md`

Scope: immutable Nexum and provider legal-document versions; conservative CloudFactory catalogue
extraction and monthly checks; offer/Service links; provider read-only and additional Nexum term UI;
contract version snapshots; version-aware contract acceptance; Customer-admin-only portal licence
ordering for exact contract-covered variants; and explicit issue, quantity, and renewal evidence.

Deployment actions: deploy the code; run `php artisan migrate --force`; run
`php artisan optimize:clear`; keep the ordinary scheduler and queue worker running. No new secret,
permission seeder, frontend build, or provider write setting is required.

Risks: CloudFactory does not guarantee legal-document fields for every product family; an extractor
must not mistake a short commercial term for legal text; accepted versions must never change
retroactively; portal products must not escape Client/contract scope; and a confirmation must be
recorded even when the provider operation later fails.

Automated verification: PHP syntax, portal licence route registration, immutable provider-version
replacement/removal behavior, Customer-admin authorization, exact contracted-offer ordering, legal
evidence hashing, and submitted CloudFactory operation linkage pass in targeted Dev tests. The full
Integration and Customer Portal suites pass with 90 tests and 876 assertions; the affected
Commercial and portal run passes with 63 tests and 686 assertions; Blade compilation and diff checks
pass. Migration batch 50 ran on Dev and backfilled all 6 existing Terms to 6 current immutable
versions with no missing current version. A live catalogue run completed for all 10,898 offers. The
current catalogue product payload returned no supported legal-document, Terms of Service, agreement,
or EULA field, so Dev correctly created no provider document and reports Not supplied by provider.

Human checks:

- [ ] Open a CloudFactory-managed Service and confirm **Provider terms** is English, read-only, and
  shows issuer, version, status, source link, and last check without full inline editing.
- [ ] Confirm **Additional Nexum terms** can add/remove an approved Nexum library document while the
  provider document cannot be removed from the Service.
- [ ] Change a provider document in a sanitized test payload and confirm a new current version is
  created while the older version remains unchanged.
- [ ] Remove that document from the next payload and confirm it remains stored with
  **Not returned in latest sync** rather than being deleted.
- [ ] Confirm a CloudFactory Service whose payload has no legal document says
  **Not supplied by provider** and does not display invented legal text.
- [ ] Send a test contract and confirm its portal view lists exact legal document versions and source
  links in addition to the existing text snapshots.
- [ ] Accept the test contract and confirm one `contract_acceptance` legal evidence row records the
  portal account/membership and captured version IDs.
- [ ] As a Viewer or site-scoped portal member, confirm Licences is hidden and the route returns 403.
- [ ] As a client-level Customer admin, confirm Licences lists only exact variants already present on
  a won, active contract and respects the Integration Client write scope.
- [ ] Order one allowlisted test licence and confirm the explicit legal checkbox, product, quantity,
  price, commitment, current versions, submitted operation, IP address, and user agent are retained.
- [ ] Confirm quantity and renewal changes each require a new explicit confirmation and record their
  previous/current quantity or renewal action.
- [ ] Confirm a provider validation/MCA failure marks the acceptance-linked operation failed without
  deleting the customer's confirmation evidence.

### HR-2026-07-21-001 - Ticket Storage Reservation Release And Quantity-Zero Removal

Status: Pending
Added: 2026-07-21
Environment: Dev
Related: `docs/rfc/2026-07-21-ticket-storage-reservation-release.md`

Scope: release a pending Storage-backed Ticket cost through a compact confirmed trash action or by
updating quantity to zero; atomically reduce reserved stock, mark reservation and cost history as
released/cancelled, remove the row from normal Ticket activity and the Storage Picking List, retain
a Ticket event audit snapshot, restore linked approved planned lines for conversion again, and
serialize update/pick/release state changes with database row locks.

Deployment actions: deploy the code; run `php artisan optimize:clear`. No migration, seeder, queue,
scheduler, or frontend build action is required. Knowledge documentation must be synchronized after
deployment according to the ordinary Knowledge process.

Risks: incorrect stock release could understate reserved inventory; stale requests could otherwise
both pick and release the same cost; released costs must not enter Economy; linked approved planned
lines must become convertible again; and the compact destructive control must remain clear and
accessible.

Automated verification: the complete affected Dev suites pass with 168 tests and 1,354 assertions
across Ticket, Ticket Workflow v3, Storage, and Economy. PHP syntax and targeted diff checks pass.
A browser check on Dev confirmed the explanatory confirmation, quantity-zero help text, and
quantity-zero redirection to the same confirmation flow; the real Dev reservation was left unchanged.
Rendered-view assertions confirm the final `Delete reservation` control is inside Edit cost and the
Activity row no longer exposes a separate trash control.

Human checks:

- [ ] Open a Ticket with a reserved Storage cost, click `Edit`, and confirm a subtle
  `Delete reservation` button appears inside the Edit cost modal rather than on the Activity row.
- [ ] Click `Delete reservation`, confirm the next modal clearly explains that stock and Picking
  List work will be released, then cancel and verify nothing changes.
- [ ] Confirm removal on a Dev test reservation and verify the cost row disappears from normal
  Activity, the Picking List row disappears, and the Storage item's reserved quantity decreases by
  exactly the released quantity.
- [ ] Confirm the Ticket Events accordion records `Storage reservation released` with a clear
  message.
- [ ] On a second Dev reservation, edit quantity to `0`, confirm the same removal modal appears,
  and verify the same release result.
- [ ] Confirm a picked cost does not expose the removal action and cannot be released by a stale
  request.
- [ ] If using an accepted planned Storage line, release its converted reservation and confirm the
  approved line can be converted again.

### HR-2026-07-20-001 - CloudFactory Two-Way Client, Catalogue, Licence, Contract, And Economy Integration

Status: Pending
Added: 2026-07-20
Environment: Dev and allowlisted CloudFactory production test customer
Related: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`

Scope: encrypted dedicated Portal-service-account connection; automatic token exchange and safe
revocation; role and health discovery; deterministic two-way Client/customer matching with manual
fallback; Vendor, catalogue, Service-source and settings-driven price synchronization; Client
licence workspace; Microsoft and Adobe provider operations; contract/commitment gates; append-only
licence changes; confirmed recurring Economy billing; scheduled polling and authenticated
notification webhooks; deterministic webhook retry deduplication; reconciliation, operation
idempotency, conflicts, audit, permissions, Knowledge documentation, and a production-only fictitious
Client validation gate.

Deployment actions: deploy the complete change; run `php artisan migrate --force`; run
`php artisan db:seed --class=PermissionSeeder --force` and
`php artisan db:seed --class=RoleSeeder --force`; run `php artisan optimize:clear`; keep the
ordinary queue worker and scheduler running. Configure a dedicated CloudFactory Portal service
account, enter its refresh token only through the masked settings field, verify the discovered roles,
select the default Service unit, enable notification registrations only when Partner Admin is
available, and leave writes limited to the fictitious allowlisted Client until the checks below pass.

Risks: CloudFactory has no sandbox and every provider call reaches production; ambiguous customer
matching must not merge records; retries must not duplicate licence orders; price and quantity
changes can affect contractual commitments and invoices; direct CloudFactory customer-portal changes
must reconcile back into Nexum; Microsoft MCA acceptance remains an interactive customer step; and
CloudFactory webhooks rely on a shared `X-API-KEY` over HTTPS rather than a cryptographic signature.
Polling therefore remains mandatory even when webhooks are enabled.

Automated verification: implementation and all three migrations completed on Dev. The dedicated
CloudFactory feature suite passed with 22 tests and 211 assertions; the latest Integration module
suite passed with 64 tests and 535 assertions; and the latest complete application suite passed with
849 tests and 6,378 assertions. PHP syntax, Blade compilation, route registration, `X-API-KEY`
rejection, old legitimate retry acceptance, deterministic deduplication, registration/removal,
queued reconciliation, durable per-category progress, canonical Vendor reuse without a duplicate
Microsoft, unresolved generic-category protection, audited manual Vendor propagation, and legacy
serialized-job compatibility passed. Authenticated HTTP smoke tests returned 200 for the CloudFactory
settings and catalogue pages, Services, contract creation, the Client licence workspace, and the
sanitized progress endpoint. The settings response verifies both collapsed sections and all four
nested operational cards. The rendered pages are regression-tested as valid UTF-8, with ASCII-safe
source escapes for the live progress separators. Rendered progress JavaScript passed syntax
validation.

The 2026-07-21 managed-Cost follow-up migration completed on Dev. CloudFactory raw commitment totals
now remain on each offer while the linked Commercial Cost and Service use a normalized monthly,
quarterly, yearly, or one-time amount. One default variant contributes provider cost per Service;
alternative variants remain synchronized without being summed, and manual Nexum Costs remain linked.
Draft contract lines select and snapshot the exact offer, normalized total cost, currency, and raw
term metadata. The CloudFactory suite passed 25 tests / 268 assertions, Commercial passed 32 / 272,
and Sales plus Economy passed 32 / 289. A final combined CloudFactory and Commercial run passed 57
tests / 540 assertions, including server-side replacement of a manipulated contract cost, catalogue
term filtering and sorting, and omission of the redundant catalogue Source column. Blade compilation
also passed. The live Dev catalogue has no
enabled or Service-linked offers yet, so the new Cost rows require the human catalogue checks below.
Commercial and Integration Knowledge synchronization processed two chapters and eleven articles;
the queued BookStack push completed without a failed job.

The later 2026-07-21 variant-Service decision supersedes the default-variant model above. Migration
`2026_07_21_190000_enforce_cloudfactory_service_variants` completed on Dev: every commitment and
billing offer now owns one deterministic variant SKU, one Service, and one managed Cost. Contract
selection stores the Service's exact offer automatically, licence issue requires that exact pair,
and inbound subscription synchronization resolves a shared provider SKU by commitment and billing.
The focused CloudFactory, Commercial, Sales, and Economy run passed 90 tests / 842 assertions.
Visual catalogue, Service, Cost, and contract verification remains pending below.

Migration `2026_07_21_200000_link_commercial_records_to_integrations` completed on Dev. CloudFactory
Services and Costs now store the same generic source Integration and remain connected through the
ordinary Commercial Cost relation. Active ownership locks Service and Cost changes through the UI,
direct requests, Commercial API, and Service pricing component. Revocation, disable, and Integration
deletion preserve and release the rows; a released Service uses its retained Cost on a new contract
without attaching the inactive CloudFactory offer. The focused Commercial and CloudFactory run
passed 59 tests / 603 assertions, and the complete application suite passed 851 tests / 6,461
assertions. The Dev backfill found no currently Service-linked CloudFactory offers, so no existing
commercial rows required conversion. Commercial and Integration Knowledge sync processed two
chapters and eleven articles with no skips; the queued BookStack push completed with the Integration
active, healthy, and without a recorded error. Visual active/released-state review remains pending.

Role discovery was corrected to use CloudFactory's authenticated `/Authenticate/Roles` endpoint
instead of token claims. The connected Dev account was refreshed without replacing its stored
refresh token and returned 18 roles. Customers/catalogue, Microsoft/MCA, invoices, notifications,
and activity log were enabled; Adobe remained unavailable because the account did not return the
Adobe role. Manual capability refresh and automatic refresh during token renewal passed tests.

A real queued Everything run completed with 26 of 26 Clients, 10,898 of 10,898 catalogue products,
and 0 of 0 licences. It recorded no conflicts and no failed queue jobs. A pre-existing serialized
CloudFactory job then completed in 29 seconds using the deployment-compatibility defaults. The
Integration Knowledge synchronization processed 1 chapter and 4 articles, and the BookStack push
completed. The Dev worker had only been switched off; production already uses the managed worker system.

A later live catalogue backfill created sixteen stable category mappings and linked 10,883 of 10,898
offers to canonical Nexum Vendors. Fifteen categories mapped automatically. All 10,638 Microsoft
offers across NCE, CSP, SPLA, software subscription, perpetual, and Azure category identities reuse
the existing Microsoft Vendor ID 1, and the Vendor register still contains one Microsoft. The generic
IaaS category and its fifteen offers remain intentionally unmapped with one open manual-review conflict.
The remaining product checks require live webhook registration and the allowlisted fictitious
Client because CloudFactory provides no sandbox.

Human checks:

- [ ] Confirm Automation, pricing, and write safety and Conflicts and recent activity are collapsed
  by default, and expanding the activity section shows the four separate conflict, sync-run,
  provider-operation, and notification-webhook cards.
- [ ] Select Everything and confirm the modal opens immediately, shows separate Clients, Catalogue
  and prices, and Licences rows, and advances real item counters while the queued job runs.
- [ ] Close the progress modal during a run, confirm the job continues, and use View current sync to
  resume watching the same run.
- [ ] Confirm the CloudFactory settings page never displays a stored refresh or access token.
- [ ] Confirm the right-sidebar setup links open CloudFactory's refresh-token flow and official API
  guide, and that the guide clearly instructs the administrator to paste the Refresh Token rather
  than the Access Token.
- [ ] Confirm API verified is understood as the last successful verification or sync, not a new request on each page view.
- [ ] Select Refresh capabilities without replacing the stored token. Confirm Customers/catalogue,
  Microsoft/MCA, Invoices, Notifications, and Activity log show Available, Adobe shows Missing role,
  and the discovered-role list and last-checked time are visible.
- [ ] Enable notification webhooks, confirm event registrations are shown without displaying the
  shared key, and verify one real provider delivery reaches a processed receipt.
- [ ] Resend or retry the identical provider delivery and confirm it is accepted without creating a
  second receipt or synchronization job.
- [ ] Run customer sync and confirm a strong match links correctly while an ambiguous match is parked
  for manual linking without modifying either customer.
- [ ] Confirm an inbound CloudFactory-only customer creates one Nexum Client and repeat sync is
  idempotent.
- [ ] Confirm catalogue offers can be excluded or enabled, and Services sort/filter correctly by
  Vendor and source. Confirm the Cloud Factory catalogue itself shows Vendor without a redundant
  Source column, while the resulting ordinary Service still shows Cloud Factory as its source.
- [ ] Confirm each offer is compact by default, shows Catalogue only and Not in Services before
  activation, and expands settings only for the selected row. Enable For sale on a test offer and
  confirm the resulting Service appears in the ordinary Services list.
- [ ] Confirm Vendor mappings shows fifteen automatic mappings and one IaaS mapping needing review;
  open a Microsoft mapping and verify it points to the existing Microsoft Vendor rather than a copy.
- [ ] After the correct canonical Vendor for IaaS is decided, link it manually and confirm the choice
  updates all fifteen IaaS offers and any already linked Services without changing the Cloud Factory
  source identity.
- [ ] Confirm the catalogue filter is labelled Vendor, not Nexum Vendor.
- [ ] Search for Microsoft 365 Business Basic, filter separately by Commitment term and Billing
  term, and confirm otherwise identical offers show their distinct combinations: monthly/monthly,
  annual/monthly, and annual/annual. Select the Commitment and Billing headings in both directions
  and confirm sorting preserves the active search and filters.
- [ ] Enable one annual-commitment/monthly-billing Business Basic test offer and confirm Cost and
  MSRP show both the raw annual source total and the normalized monthly Nexum amount.
- [ ] Open the generated Service and Cost and confirm both are marked Cloud Factory and Managed,
  the source badge links to the active Cloud Factory Integration, both use the Microsoft Vendor,
  neither record can be edited or deleted, and the Cost appears through the ordinary linked Costs
  section on the Service.
- [ ] Enable annual-commitment/monthly-billing and annual-commitment/annual-billing variants of the
  same product. Confirm Nexum creates two separate Services with distinct SKUs ending in
  `-C12-B1` and `-C12-B12`, without a Make default or manual Service-link control.
- [ ] Confirm each variant Service has only its own Cloud Factory managed Cost. Add a manual Nexum
  Cost to one variant and confirm it is preserved there without appearing on the other variant.
- [ ] Add each variant-specific Service to a draft contract. Confirm there is no additional
  commitment selector and that displayed sale price, cost, interval, yearly profit, and the saved
  contract line use the exact offer owned by the selected Service.
- [ ] Confirm catalogue offers can still be excluded or enabled after Vendor mapping.
- [ ] Confirm MSRP, MSRP markup, cost markup, and manual price modes behave as configured and a
  monthly refresh does not overwrite a manual price.
- [ ] Confirm licence issue is blocked for a Client without an eligible contract.
- [ ] On the allowlisted fictitious Client, create/link the CloudFactory customer and perform one
  reversible low-risk licence operation; confirm provider state reconciles into the Client licence
  workspace.
- [ ] Confirm provider activation creates the expected contract amendment and Economy draft billing
  line once, with no duplicate after a repeated sync/generation run.
- [ ] Make a permitted direct CloudFactory/customer-portal change and confirm it reconciles into
  Nexum with origin and audit history.
- [ ] Disable webhooks and confirm provider registrations are removed before the shared key is
  deleted; re-enable them for continued validation if required.
- [ ] For the controlled test Service and Cost, record their IDs and normal relation, then
  revoke/disconnect. Confirm webhook registrations, scheduled sync, and writes stop without exposing
  a secret.
- [ ] Confirm the same Service, Cost, relation, accepted contract data, and accounting basis remain
  after disconnect, both rows show Released to Nexum and are editable, and selecting the Service on
  a new draft contract uses the retained Cost without attaching the inactive Cloud Factory offer.

### HR-2026-07-17-001 - Ticket Workflow v3 Conditional Actions, Escalation, Review, And Commercial Approval

Status: In Review
Added: 2026-07-17
Environment: Dev
Related: `docs/rfc/2026-07-17-ticket-workflow-v3-conditional-actions-and-escalation.md`

Scope: Signal-style grouped workflow requirements; versioned workflow-specific states; per-state
action visibility and server enforcement; optional or required manual internal escalation;
eligible-owner assignment; senior review and scoped response/signature evidence; planned Ticket
costs; shared Sales Opportunity/Quote handling with immutable PDF and customer acceptance; approved
Storage, purchase-need, implementation, and Economy completion; API parity; explicit close outcomes;
active-Ticket workflow-version migration with automatic requirement-based placement per Ticket; and
Published-only customer status updates selected per transition through the shared Email template
and Customer Portal notification systems.

Deployment actions: deploy the complete cross-module change together; run `php artisan
migrate --force`; run `php artisan db:seed --class=PermissionSeeder --force` and `php artisan
db:seed --class=RoleSeeder --force`; run `php artisan optimize:clear`; and keep the ordinary queue
worker running for quote email/PDF and downstream jobs. Run `npm ci` and `npm run build` because
the Workflow editor now relies on Livewire 3's bundled Alpine runtime and the separate Alpine
package was removed. Before production, preview automatic active-Ticket target proposals and migrate only
explicitly selected Tickets. Run `php artisan db:seed --class=EmailTemplateSeeder --force` to ensure
the editable `tickets/ticket_status_update` template exists before enabling transition updates.

Risks: an incorrect action or assignment policy can block technicians; incomplete target-step
requirements can propose the wrong automatic placement for active Tickets; customer messages and
signatures must be classified against the correct Ticket and scope; quote acceptance is commercially
significant; purchase conversion must remain a draft need and never send a vendor order automatically;
and declined/cancelled/no-sale closure must not create ordinary Economy output. A transition notification can contact a customer,
so administrators must verify the selected template, channels, message, and Published-only behavior
before enabling it on a production workflow.

Automated verification completed 2026-07-17 on the active Dev tree: the four new migrations passed
fresh, rollback, reapply, and the partially applied MySQL recovery path before being applied to Dev;
PHP syntax and Blade compilation passed; Ticket, Workflow v3, Sales, Storage, and Economy completed
164 tests / 1314 assertions, including 8 tests / 86 assertions in the focused Workflow v3 suite. A
rendered authenticated response check confirms that the create page contains Workflow steps,
grouped requirements, available actions, and escalation paths. Repository Knowledge synchronization
processed Economy, Integration, Sales, Storage, and Ticket documentation, and the synchronous
BookStack push reported an active, healthy integration with no last error. Browser automation could
not cross the internal Dev certificate, so the visual checks below remain required.
The compact Available actions refinement was also verified on Dev with 117 Ticket and Workflow v3
tests / 834 assertions. Its Livewire regression test confirms that the plus-button adds only the
selected action and that removing it returns the action to inherited behavior.

One follow-up failure mode for Add action and Add next step was compiled Blade views created as
read-only for the PHP-FPM group. Those Livewire calls reached the server but failed while rendering
their updated component. Dev's compiled-view cache was cleared and rebuilt with group-writable
`0664` files under `storage/framework/views`; a default group-write ACL now also protects files
created by later web or CLI rendering. The permanent Dev cache procedure was recorded in
`AGENTS.md`. The two focused Livewire regressions plus the authenticated Workflow editor rendering
test completed 3 tests / 40 assertions.

Human review then confirmed both buttons could still appear inert without producing any new server
request. The remaining client-side cause was a second Alpine runtime imported and started by
`resources/js/app.js` before Livewire 3 initialized its bundled Alpine runtime. The first runtime
claimed the Workflow's `x-data` subtree before Livewire registered its `wire:click` directives.
The separate Alpine import and package dependency were removed, Livewire 3 is now the sole Alpine
owner on authenticated pages, and the Vite assets were rebuilt. The frontend-runtime regression,
full Workflow v3 suite, and authenticated editor rendering completed 14 tests / 146 assertions.

The Workflow editor refresh defect reported during human review was corrected on Dev. Ordinary
fields now use deferred Livewire binding, accordion opening is handled locally, and dependent
requirement/operator and escalation-target selectors update locally with Alpine. Only explicit
structure changes such as adding or removing a step, group, requirement, action, or path perform a
Livewire server update, and those actions keep their source step open. The focused Workflow v3 suite
completed 11 tests / 123 assertions; the combined Workflow v3, Ticket, and Portal verification
completed 126 tests / 938 assertions.

The Workflow steps accordion now starts with every existing step collapsed so the builder remains
compact when opened. A newly added step still opens automatically, and structural actions continue
to keep their affected source step open. The focused initial-collapse and add-next-step regressions
completed 2 tests / 21 assertions; the expanded Workflow v3 and authenticated editor rendering
verification completed 13 tests / 141 assertions.

Each collapsed Workflow step header now exposes a compact Remove step action whenever the workflow
contains more than one step. Removing a step also removes its connected transitions and escalation
paths through the existing editor logic. The final remaining step is protected in both the UI and
the Livewire component. The frontend-runtime regression, full Workflow v3 suite, and authenticated
editor rendering completed 15 tests / 154 assertions on Dev.

The updated Ticket Workflow article was synchronized and pushed to BookStack; the integration
reported active, healthy, and no last error after the push.

Human review clarified that workflow progress belongs in the Ticket header rather than in a large
Workflow card in the Ticket body. Dev now renders an ordered, connected header rail with the
current step highlighted and evaluated requirements available on hover or keyboard focus.
Next-step and escalation actions are in the compact right panel, while commercial approval, review,
and evidence remain separate task-focused tools. The workflow-decisions API exposes the same ordered
step and requirement data. Ticket and Workflow v3 verification completed 118 tests / 845 assertions.
The visual refinement reported during review replaces separate Bootstrap status pills with one
compact workflow rail: restrained labels, connected arrow lines, distinct current/completed/
available/upcoming markers, and a small warning indicator for missing requirements. The Vite build,
Blade compilation, and focused Ticket header test (1 test / 10 assertions) passed on Dev.

Human review on 2026-07-17 found a workflow runtime defect on `TD-2026-000019`. An Internal
solution was stored and both the pinned definition and requirement evaluator report an allowed
transition, but the Ticket remained in `New`. Diagnosis found that the direct Internal solution
path does not invoke requirement-driven auto-advance, while action-trigger lookup reads mutable
transition rows even when the Ticket runtime and UI read its pinned workflow-version definition.
No Ticket data was changed during diagnosis; this review remains Rework Needed until the runtime
uses one version-consistent definition and the regression is verified.

The runtime defect was corrected on Dev on 2026-07-17. Message triggers, requirement-driven
advance, manual and API status changes, completed closure, and inbound Relationship status sync now
delegate to the same pinned workflow transition action. A successful move updates status,
`workflow_state_key`, workflow history, and Ticket events together. Saving a draft no longer drops
existing automatic message triggers, and the editor exposes a compact `Automatic after action`
selector for the supported message activities. Focused verification completed 125 Ticket tests /
907 assertions plus 10 Relationship tests / 37 assertions; dedicated regressions cover the exact
Internal solution/pinned-version defect, API status parity, and terminal close history. The review
remains Rework Needed until the checks below are repeated in the browser by the named reviewer.

The automatic-action model was expanded after review clarification on 2026-07-17. A transition can
now select **Any technician activity** or a specific action. The business action is persisted first,
then the pinned workflow evaluates transition and target-step requirements; a passing activity moves
at most one non-finishing step, while an unmet gate leaves the action saved and the Ticket in place.
Opening the page alone is not an activity. Timer start is now a real server-audited action rather
than only browser local state, and timer start, time registration, and actual-cost registration have
API parity. Verification completed 128 Ticket/Workflow v3 tests / 936 assertions, 41 Sales,
Storage, and Relationship tests / 421 assertions, and the frontend runtime regression (1 test / 5
assertions). This includes requirements becoming true after an Asset link and independent API
regressions for timer, time, and cost activity. The expanded Ticket suite exposed a Blade compiler
error in the new timer control; the timer decision now uses a proper Blade PHP block, all 108 Ticket
tests pass, and compiled views pass. The Dev HTTP smoke check returns the expected authentication
redirect rather than an application error. Repository Knowledge pushed 2 chapters and 14 pages to
BookStack synchronously with zero failures or skips; the integration reports healthy with no last
error.

The final hard-coded requirement-only solution advance was replaced by schema-versioned
compatibility: already-published definitions keep their historical behavior, while every new or
republished definition moves only from its configured action triggers. A regression confirms that
a satisfied solution requirement alone cannot move a current workflow definition.

The separate solution fact was verified after the latest human-review question. **Solution is
marked** accepts either a marked public technician reply or a
marked internal note when Ticket Settings permits internal solutions. The latest Dev data also
evaluates this fact as satisfied for Tickets containing only an allowed internal solution. A manual
transition becomes available without moving by itself; automatic movement still requires its own
configured action trigger. The complete Ticket suite passed 135 tests / 1004 assertions. Ticket
Knowledge synchronized 1 chapter / 11 pages and pushed them to BookStack with zero failures or
skips; the integration reports healthy with no last error.

Review of `TD-2026-000031` found that its assigned technician is correctly stored as the Ticket
owner and the owner fact evaluates true. The misleading header came from a workflow condition using
the negative `is_false` operator while the progress rail displayed only the positive fact label.
Evaluated requirements now carry an operator-aware summary, the editor calls the boolean choices
**must be true** and **must be false**, and negative gates display with **Must not**. An initial
Ticket Body stored as the default context note is excluded from both Internal-note and technician-
response activity facts; a real later note still counts. Identical requirements configured on both
a transition and its target step remain enforced in both scopes but display once in the header.
The complete Ticket suite passed 136 tests / 1022 assertions. Ticket Knowledge synchronized 1
chapter / 11 pages and pushed them to BookStack with zero failures or skips; the integration is
healthy with no last error.

The response wording and behavior were separated after further review. **Technician reply or
internal note exists** is now an activity fact satisfied by either a real later public technician
reply or a real later internal note; no solution marker is required. **Solution is marked** remains
a separate fact and only passes for a message explicitly selected as the solution. The initial
Ticket Body remains excluded from both activity facts. The complete Ticket suite passed 136 tests /
1024 assertions, and Ticket Knowledge pushed 1 chapter / 11 pages to BookStack with zero failures
or skips; the integration is healthy with no last error.

The activity fact was then split into two independently configurable facts: **Technician reply
exists** and **Internal note exists**. Administrators can put both in a **Require at least one**
group for reply-or-note behavior, or require both through **Require all** or separate required
groups. **Solution is marked** remains independent. The generated default solution transition now
requires only the solution marker, so an allowed internal solution is not forced to masquerade as a
public reply. The initial Ticket Body still satisfies neither activity fact. The complete Ticket
suite passed 136 tests / 1027 assertions, and Ticket Knowledge pushed 1 chapter / 11 pages to
BookStack with zero failures or skips; the integration is healthy with no last error.

Read-only review of `TD-2026-000031` confirmed that its published In Progress requirements contain
one **Require at least one** group with **Internal note exists** and **Technician reply exists**.
The requirement evaluator already accepted either condition, but workflow progress flattened the
group and then treated every displayed condition as mandatory. Ticket View and the API now preserve
the evaluated OR result and present the alternatives as one combined gate, for example **At least
one: Internal note exists OR Technician reply exists**. With only the existing internal note, the
real Ticket now reports the combined gate and `requirements_passed` as true. The complete Ticket
suite passed 137 tests / 1036 assertions. Ticket Knowledge pushed 1 chapter / 11 pages to BookStack
with zero failures or skips; the integration is healthy with no last error.

Customer status updates were added to next-step transitions on 2026-07-18. Administrators can
choose Email and/or Customer Portal, an active Ticket Email template, and an optional safe message.
The same after-commit runtime covers manual Ticket buttons, automatic action triggers, and API
transitions. Only Published Tickets may create or send the public update; Unpublished Tickets still
transition but record a skip. The portal workflow channel is database-only so it cannot duplicate
the selected templated Email. Internal notes, internal workflow names, requirements, and cost data
are excluded from generated content. Missing recipients/configuration and SMTP failures are audited
without reverting the transition, and idempotent API retries do not duplicate the update. The
additive migration was applied to Dev and the default Email template was seeded. The focused slice
passed 10 tests / 52 assertions; combined Ticket, Workflow v3, Email, Notification, and Customer
Portal regression passed 207 tests / 1399 assertions. Repository Knowledge synchronization
processed two chapters and twelve Ticket/Email articles without skips; the synchronous BookStack
push left the active integration healthy with no last error.

Automatic active-Ticket placement was added on 2026-07-18. The migration page no longer asks the
administrator to map an old step to one target step. Preview evaluates every Ticket against the new
version and explains whether it retained a stable step, matched explicit entry requirements,
preserved a unique reporting status, used the initial step, or was blocked. Apply evaluates again in
the transaction and ignores legacy API `state_mapping` input. Focused regression covers two Tickets
from the same old step being placed differently, a blocked Ticket remaining pinned, stable-key
retention, an authenticated browser rendering without the Target-step selector, API parity, and
recorded requirement/strategy history. Workflow v3 passed 24 tests / 252 assertions; the combined
Workflow v3, Ticket, and customer-notification regression passed 142 tests / 1056 assertions. Blade
compilation passed and the Dev HTTP smoke check returned the expected authentication redirect.
Ticket Knowledge synchronized one chapter and eleven articles without skips; the synchronous
BookStack push left the active integration healthy with no last error.

Partial human review update on 2026-07-18: Svein Tore reports that the tests performed so far look
correct. The review is now **In Review**; the unchecked browser scenarios below remain open and this
partial confirmation is not recorded as final approval.

Pre-push verification on 2026-07-18 completed the full Laravel suite with 820 tests / 6075
assertions, plus `npm ci` and the production Vite build. The first full run exposed one stale System
Knowledge article-count expectation caused by the separate application-version documentation; that
expectation was corrected outside the Workflow commit, its focused regression passed 1 test / 7
assertions, and the complete suite then passed. The production dependency audit still reports one
existing moderate PostCSS advisory; dependency upgrades remain separate from this Workflow change.
After Pint normalized the staged PHP files, the final Ticket and cross-module regression passed 287
tests / 2199 assertions and the staged formatter/diff checks passed.

In-app visual automation remains blocked by Dev's internal certificate, so the browser checks
below are still required.

- [ ] Configure `Any technician activity` with a required linked Asset. Add a note without an Asset
  and confirm it is saved without moving the Ticket; then link the Asset and confirm that action
  moves the Ticket exactly once to the configured next step.
- [ ] On separate Tickets, start the timer, register time, and add actual cost. Confirm each action
  can move to the configured next step in both the Ticket page and API, while merely opening the
  Ticket does not change its state.

- [ ] On a workflow next-step button, enable **Notify customer**, select Email and Customer Portal,
  choose `Ticket status update`, and add a customer-safe message. Publish the workflow and the test
  Ticket, then trigger the transition with an internal note. Confirm the Ticket moves once, the
  public timeline shows only the approved reporting status/message, one templated Email is queued,
  and one portal notification is created without a second generic Email.
- [ ] Repeat the configured transition on an Unpublished Ticket. Confirm the Ticket moves internally
  but no public status-update message, customer Email, or portal notification is created, and the
  audit history explains that delivery was skipped because the Ticket was not Published.
- [ ] Trigger equivalent configured transitions once from a manual Ticket next-step button and once
  through the API. Confirm both produce the same public update and delivery behavior, and repeating
  the same API idempotency key does not produce another message or notification.

- [ ] Open the Workflow create and edit pages and confirm every existing step starts collapsed;
  expand one manually, then add a next step and confirm only the newly added step opens
  automatically.
- [ ] Create a manual transition requiring **Solution is marked**
  and leave **Automatic after action** empty. Add an Internal solution and confirm the transition
  becomes available without moving the Ticket until the button is clicked. Then configure the
  Internal solution action trigger on another transition and confirm it executes exactly once and
  updates both status and workflow state. Verify the API reports the same requirements and resulting
  state.
- [ ] With multiple steps, confirm each collapsed header shows Remove step; remove a middle step
  and confirm its connected next-step buttons and escalation paths disappear. Confirm the final
  remaining step has no Remove step control and cannot be deleted.
- [ ] Confirm Available actions initially shows the compact selector and plus-button, displays only
  actions explicitly added, and removes an action back to inherited behavior.
- [ ] Type a multi-word Step name and change status, roles, requirements, operators, action policy,
  assignment, and escalation fields; confirm ordinary edits do not refresh or collapse the editor.
  The step header may update after save or the next explicit structure action.
- [ ] Open a later step, select an action and click Add action, then click Add next step and
  add/remove a requirement or next-step button; confirm each change appears without a server error
  and the necessary Livewire update keeps that source step open.
- [ ] Confirm the Ticket header shows one clean connected workflow rail rather than separate pills;
  verify the current, completed, available, and upcoming markers are easy to distinguish without
  competing visually with Close/Back, and that no large Workflow card appears in the Ticket body.
- [ ] Hover and keyboard-focus every step to verify satisfied and missing requirements, then confirm
  Next step/escalation actions and the separate commercial/review/evidence tools remain usable.
- [ ] Create a Ticket assigned to a technician and confirm **Ticket has an owner / must be true** is
  satisfied. Configure the same fact as **must be false** and confirm the header explicitly says
  **Must not: Ticket has an owner** instead of claiming that an owner is missing.
- [ ] Confirm the initial Ticket Body satisfies neither **Internal note exists** nor **Technician
  reply exists**. Put both in a **Require at least one** group and confirm that, while neither exists,
  Ticket View shows one failed combined **At least one** gate rather than two mandatory failures.
  Add only a real internal note and confirm the combined gate turns satisfied and the configured
  next step becomes available; then repeat with only a public technician reply. Confirm the API
  reports the same group result. Confirm **Solution is marked** remains false until one message is
  explicitly marked as the solution. Place the same fact on a transition and its target step and
  confirm the header shows it once.

- [ ] Build and publish a test workflow with `all` groups and an `any` group containing customer
  response, uploaded signature, and valid contract; confirm a linked Asset can be a separate group.
- [ ] Confirm hidden, blocked, and conditional Ticket buttons match the workflow and the same direct
  API calls are denied with an understandable reason.
- [ ] Confirm an optional escalation remains a technician choice and a required escalation blocks
  only its configured protected actions.
- [ ] Escalate a Ticket to another workflow/queue/type and verify only an eligible technician can be
  selected or automatically assigned.
- [ ] Request senior review as a junior, approve as another eligible senior, then change a material
  Ticket field or planned line and confirm the approval is invalidated.
- [ ] Classify a specific customer email response and a specific uploaded signature; confirm an
  unrelated message/file or another customer's record cannot satisfy the gate.
- [ ] Add equipment and implementation/time as planned scope, create the shared Sales quote, and
  verify the Ticket and Sales views operate the same Opportunity and Quote.
- [ ] Send the quote from the Ticket and verify the reply includes the immutable PDF and matching
  acceptance link; accept through the link, then separately test recorded email-text acceptance.
- [ ] Confirm acceptance marks the Opportunity won and unlocks only the approved lines; converting
  an orderable item creates a draft purchase need without sending a vendor order.
- [ ] Complete implementation and close as `completed`; confirm unfinished required work or a cost
  overrun outside tolerance blocks closure and requires corrected scope/reapproval.
- [ ] Close separate Tickets as customer declined, cancelled, and no sale; confirm a reason is
  required and ordinary Economy output is not created.
- [ ] Put two active Tickets in the same old workflow step, add an Internal note to only one, and
  publish a version where a renamed later step requires that note. Confirm migration preview has no
  **Target step** selector, automatically proposes the later step only for the Ticket with the note,
  explains both proposals, and disables a Ticket that cannot safely match any target. Migrate one
  selected Ticket and confirm it is re-evaluated into the proposal while the other remains pinned to
  its prior version. Confirm the API behaves the same without `state_mapping` and cannot force a
  legacy mapping that conflicts with the Ticket facts.
- [ ] Verify a technician lacking Sales, Storage, review, escalation, or workflow-publish permission
  never gains that capability from workflow configuration or through API access.
- [ ] Review Ticket detail and the workflow builder on desktop and narrow/mobile layouts, including
  disabled-reason text, modals, tables, and the `Escalate Ticket` control.

Reviewer:
Reviewed date:
Result / notes:

### HR-2026-07-16-001 - Automatic Release Metadata And Admin GitHub Version Status

Status: Pending
Added: 2026-07-16
Environment: Dev, followed by GitHub `main` for the release workflow
Related: `docs/rfc/2026-07-16-version-and-github-update-status.md`

Scope: automatic `version.txt` and Composer commit identity, Release Please configuration, footer
version display, deferred GitHub release/branch comparison in the Admin header, and the move of the
Admin landing route/view into the System module.

Deployment actions: run Composer before Laravel configuration caching, set
`APP_UPDATE_BRANCH=Dev` on the development server, clear Laravel caches, and rebuild frontend
assets only if the deployment process requires it. There are no migrations or queue actions.

Risks: GitHub status may be cached or unavailable; an installation that skips the Composer
metadata refresh can show a stale or unknown commit; and the Release Please workflow cannot create
its first release pull request until it reaches `main` with repository Action permissions enabled.

Automated Dev verification completed 2026-07-16: PHP syntax and release configuration passed;
System 31 tests / 197 assertions passed; Blade templates compiled; Composer metadata reported
repository HEAD `42a08a7` after `composer install`; a live read-only GitHub query reported
`v0.2.0-beta`, the existing release, and 7 commits behind configured branch `main`; and the HTTPS
Admin smoke check redirected unauthenticated access to login. Automated visual inspection was
blocked by the internal Dev certificate, so it does not replace the checks below.

- [ ] The shared technician footer shows only the installed version and the text is readable in
  both light and dark appearance.
- [ ] `/tech/admin` opens without waiting for GitHub and keeps the expected Admin cards and links.
- [ ] The right side of the Admin header shows the installed version and short commit ID.
- [ ] After loading, the header reports the latest GitHub release and the correct distance from the
  environment's configured update branch.
- [ ] Narrow/mobile layout wraps the status without covering the Admin title or breadcrumb.
- [ ] A temporary GitHub failure shows an honest unavailable or cached state and does not break the
  Admin page.
- [ ] A user without `system.view` cannot read the version-status endpoint.
- [ ] After the workflow first reaches `main`, GitHub creates or updates the Release Please pull
  request without publishing a release prematurely.
- [ ] Merging a future generated Release Please pull request updates `version.txt` and
  `CHANGELOG.md`, then creates the expected semantic beta tag and GitHub release.

Reviewer:
Reviewed date:
Result / notes:

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

**Automated release-candidate verification (2026-07-22):** the full Dev suite passes with 853 tests
and 6,494 assertions. PHP syntax, Composer validation, Release Please JSON/build-metadata checks,
route registration, Blade compilation, migration status, secret-pattern scanning, and Git diff
checks pass. All seven new migrations are applied on Dev. Twenty-five untracked patch backups and
four command-output artifacts were removed before staging; no application source or database data
was removed. Main was deliberately left unchanged.

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
