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
| HR-2026-07-22-001 | CloudFactory versioned legal documents and portal licence ordering | Pending | 2026-07-22 |  |  |
| HR-2026-07-21-001 | Ticket Storage reservation release and quantity-zero removal | Pending | 2026-07-21 |  |  |
| HR-2026-07-20-001 | CloudFactory two-way Client, catalogue, licence, contract, and Economy integration | Pending | 2026-07-20 |  |  |
| HR-2026-07-17-001 | Ticket Workflow v3 conditional actions, escalation, review, and commercial approval | In Review | 2026-07-17 | Svein Tore |  |
| HR-2026-07-16-001 | Automatic release metadata and Admin GitHub version status | Pending | 2026-07-16 |  |  |
| HR-2026-07-15-002 | Signal feed, rule builder, execution recovery, and retry | Pending | 2026-07-15 |  |  |
| HR-2026-07-15-001 | Main and Dev pre-merge user-interface review | In Review | 2026-07-15 | Svein Tore |  |

## Open Reviews

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

## Reviewed History

No completed human reviews have been recorded yet.
