Supplier Order Automation turns explicitly selected inbound supplier confirmations into durable
Storage imports. When policy permits, a validated import can register a Purchase Order for goods
that were already ordered outside Nexum. It never places an order with the supplier.

## Unified Supplier Orders List

Open **Storage > Supplier Orders** to follow supplier confirmations, registered Purchase Orders, and
goods awaiting receipt in one list.

An import without a Purchase Order appears as one incoming row. Use **Incoming** or **Needs
attention** to focus intake, retry, and exception work. When an import links to a Purchase Order, the
list shows only the canonical Purchase Order row and includes an **Import #** audit link. The import
does not remain as a second editable order row.

The row status and stage show whether Nexum is detecting the document, extracting data, resolving
Items, validating facts, applying policy, finalizing the order, or waiting for goods. Search, exact
status, supplier, destination, expected-date, tracking, import-stage, and extraction-method filters
share the same work surface.

## Review An Import

Open the **Import #** link to inspect:

- The sanitized source and trusted sender-authentication facts.
- Extracted order fields and lines beside their source evidence.
- Totals, charges, delivery facts, confidence, and validation results.
- Item matches, unresolved or ambiguous lines, and saved supplier mappings.
- The pinned policy and profile versions, processing attempts, reason codes, and retry state.
- The resulting Purchase Order when finalization succeeded.

Normal successful imports can remain quiet. The unified list keeps exceptions, retries, duplicates,
profile-health warnings, and rejected sources discoverable. Review changes the normalized proposal
or its mappings; it never edits the immutable source snapshot.

## One Purchase Order Across Manual And Email

The Supplier Orders list is canonical for every supplier order. A manually registered order and an
order created from supplier email do not live in separate lists. The source icon shows whether an
order was registered manually, created from supplier email, or registered manually and later
confirmed by a supplier email.

The **Nexum order number** is the internal reference. The separate **Supplier order number** is the
number assigned by that supplier. Nexum uses the exact normalized combination of supplier and
supplier order number to prevent duplicates across manual registration and email automation. The
database removes only surrounding spaces and normalizes letter case; leading zeros, punctuation,
tabs/newlines, and internal spacing remain significant. The same order number may therefore exist
at another supplier. A generated database key updates automatically even for raw data writes, and
the supplier/key constraint also reserves soft-deleted order history.

When a trusted email matches an active manual order, Nexum compares destination, currency, explicit
order date, resolved Items, quantities, supplier SKUs, unit and line prices, goods subtotal, freight,
discount, other charges, tax, and available header totals. A compatible confirmation is linked to
the existing order and its source card becomes available. Nexum does not replace the internal
number, status, dates, lines, creator, shipment or receipt history, or stock state. The supplier and
supplier order number are then locked against ordinary edits so the PO cannot be separated from its
evidence. A governed AI repair may correct them only before operational history and updates the PO
and import together.

A material disagreement, deleted order, or cancelled order is held in `needs_attention`. The import
page shows the reason and, when available, a button to review the matching Purchase Order. It does
not create a second order or silently overwrite either version. Manual create and update use the
same database-generated identity, including soft-deleted history. Canonical email header and line
projections must also agree with the stored import rows before any order can be linked or created.

A blank Supplier order number is allowed when the supplier has not assigned one yet, but it cannot
be matched automatically. Add the supplier's number to the manual order before processing the
confirmation when automatic reuse is required.

## Policy Modes And Safe Rollout

Open **Admin > Storage > Supplier Order Automation** to control order handling and AI assistance.
Open **Admin > Storage > Supplier profiles** to create, test, activate, pause, roll back, import, or
export declarative supplier profiles.

Purchase Order email automation is **off by default**. The effective decision always uses the most
restrictive applicable installation, Integration, Storage, supplier-profile, and rule setting.

- **Off** stops automated processing and Purchase Order creation.
- **Test only - creates nothing** extracts and validates for comparison without creating an Item or
  Purchase Order.
- **Prepare for review** creates an editable proposal and requires an authorized person to finish it.
- **Register automatically from an approved supplier profile** permits registration only from a
  healthy active deterministic profile after every hard gate passes.
- **Register automatically after supplier profile or AI verification** also permits governed AI
  extraction, but only after the same evidence, arithmetic, identity, confidence, and Storage
  validation gates pass.

For a cautious rollout, you can start a new supplier or changed template with **Test only**, review
the result, and then move to **Prepare for review** or an automatic mode. This is optional: automatic
profile-or-AI handling can bootstrap a trusted unknown supplier without routine approval when active
Supplier and Item creation are selected and every limit passes.

### Simple AI Fallback Setup

The normal form contains only choices that affect order handling:

1. Choose order handling and the default destination warehouse.
2. Choose what Nexum should do with an unknown supplier or Item.
3. Choose whether AI is off, runs only when the supplier profile cannot finish the order, or verifies
   every order.
4. Choose one active agent assigned to Storage. Provider and model come from that agent.
5. Set the business limits that must send an order to manual review and choose notifications.

Nexum creates and maintains the isolated structured workload and protected system actor
automatically. The user does not create a second agent, configure a workload, select a human
automation user, or choose learning, consensus, confidence, provider-outage, timeout, token, cost,
retry, circuit-breaker, retention, or JSON settings. When AI is enabled, Nexum internally enables
one-sample verified profile activation; turning AI off disables learning. Secondary consensus and
browser-defined cost rules remain off. Nexum applies the complete technical preset on the server and
ignores those values if they are added to a browser request. The backend retains historical fields
for immutable older policy revisions and pinned imports.

The selected agent may also be used elsewhere, but Supplier Order Automation receives only its
instructions, provider and model. Its tools, actions, data sources and API scopes are never made
available to this workflow. Storage sends a strict, sanitized evidence payload, treats the response
as a proposal, and performs every Supplier, Item and Purchase Order write itself after deterministic
validation.

Unattended writes are attributed to the protected **Nexum Supplier Order Automation** system actor.
It cannot sign in, has no roles, is hidden from ordinary User Management and has only the direct
permissions required by this workflow. A missing, changed or unauthorized system actor fails
closed and is repaired to the intended least-privilege state when the policy is saved.

### Automatic First-Supplier Bootstrap

When a trusted supplier confirmation has no matching profile, AI may propose both the canonical
order facts and a declarative profile. The model does not write data. Nexum validates immutable
source integrity, authenticated sender alignment, exact evidence, arithmetic and business limits;
creates or reuses the active Supplier through the guarded Documentation action; replaces profile
matching with server-owned account, mailbox, recipient, sender and authenticated-domain scope; and
requires exact reproduction of the canonical order.

For a new profile, the same verified source becomes a protected `ai_verified_bootstrap` fixture.
Nexum replays it, validates and activates the Supplier-linked immutable version before any Item or
Purchase Order is written. Unknown SKUs can then create distinct active/orderable Items and Supplier
mappings within **Maximum new Items per order**, followed by one editable Purchase Order in
`ordered` status. A later matching confirmation can use the learned profile deterministically.

Bootstrap is serialized on the Supplier and rechecks current matching, so retries and two close
first messages reuse one profile instead of creating equal-priority duplicates. Missing trust,
unsafe or non-reproducing candidates, ambiguity, duplicate conflict, unavailable AI, or any business
limit stops in attention. A source-verified Supplier may remain as the canonical identity after a
candidate failure, but no Item, mapping, Purchase Order, receipt, Movement, Stock Unit, or on-hand
quantity is created by the incomplete bootstrap.

The bundled Itegra library entry is installed as an inactive draft with a validated synthetic
protected fixture. It has no creator or automation actor, and its synthetic recipient cannot route
production mail. An administrator must clone or edit installation-specific mailbox and recipient
routing, replay the protected fixtures, and activate the selected version explicitly.

## Trusted Source Requirements

The visible **From** address is not proof of identity. Automatic processing requires the configured
mailbox and recipient, an allowed supplier source, and the required SPF, DKIM, DMARC, and alignment
facts derived only from trusted receiving hops. Storage repeats these checks even when Email and
Signal rules already matched the message.

A source that fails trust requirements cannot auto-register an order. It is held for attention or
rejected with a reason. Product descriptions, message footers, HTML, links, and AI-facing content
are treated as untrusted data. Nexum does not fetch remote images, tracking links, product URLs, or
other network resources from the message.

Supplier identity is tied to trusted evidence and the Documentation supplier register. A display
name alone never merges or selects a supplier. When policy allows supplier bootstrap, creation still
runs through Documentation's guarded supplier action; otherwise the import remains in review.

A canonical order date must either be extracted explicitly or come from the immutable
`received_at` value pinned on the source snapshot under an approved received-date fallback. Missing
or invalid source time holds the import for review; Nexum never substitutes the current date.

## Profiles, Versions, And Item Mappings

A supplier profile is declarative configuration for one supplier document family, not executable
PHP or JavaScript. It describes safe matching, labels, tables, locale, field mappings, required
evidence, normalizers, limits, defaults, and validation rules.

Profile lifecycle is `draft`, `shadow`, `active`, `degraded`, `paused`, or `retired`. Each definition
is stored as an immutable version with status `draft`, `validated`, `active`, `superseded`, or
`rejected`. Activating a version does not rewrite an earlier version or an in-flight import. Test
the version against protected fixtures and shadow samples before activation. Rollback selects an
earlier validated version without deleting the failed history.

An automatically learned first-supplier profile is linked to the resolved Supplier and records the
machine-protected bootstrap fixture and reproduction metrics. Existing-profile changes keep their
normal protected-fixture and historical-sample gates; the first-message bootstrap does not weaken
later profile-change review rules.

Administrators can edit the profile container's name, slug, description, and matching-scope copy.
Every accepted change requires a reason and appends an immutable audit row containing the actor,
changed fields, and exact before/after metadata snapshots. The audit also pins the active version
ID and checksum visible at the time of the edit. Duplicate slugs, an unchanged submission, an
inactive or unauthorized actor, and unsafe or unbounded matching selectors are rejected.

This metadata form never edits an immutable parser version. In particular, the container
matching-scope copy is descriptive and audited; runtime source matching continues to read the
active version's `definition.match` block. To change live mailbox, recipient, sender, trust,
subject, or body matching, create a new version, test it against protected fixtures, and activate
it deliberately. Activating a version refreshes the container copy from that version. This
separation prevents a metadata edit from silently changing how in-flight supplier confirmations
are interpreted.

Profile JSON exposes both `defaults` and `item_defaults`. These blocks hold bounded warehouse,
currency, received-date fallback, VAT, tracking, warranty, lead-time, and minimum-order defaults.
They are configuration only and remain subject to canonical validation and the effective policy.

Nexum resolves each source line conservatively:

1. Use exactly one valid supplier and supplier-SKU mapping.
2. Use another explicitly allowed unique identifier only when uniqueness can be proven.
3. Use a mapping previously confirmed during review.
4. Create a distinct supplier-imported Item only when the effective policy permits it.
5. Otherwise leave the line unresolved for review.

Zero matches never trigger a name-only merge, and several matches never select an Item arbitrarily.
When a reviewer confirms a line, Nexum saves the exact supplier-SKU mapping so later confirmations
can resolve deterministically. A policy-created Item starts with zero quantity and keeps its source
provenance and review state.

## Manual Correction

An authorized reviewer can open a non-terminal import and choose **Correct Manually**. The
structured form covers supplier name, external order number, order date, currency, destination
warehouse, every existing source line, freight, discount, other charges, total excluding tax, and
a required audit reason. Reviewers can add missing lines, remove incorrectly parsed lines, and the
form reindexes the remaining lines before submission while enforcing the 500-line server limit. It
does not accept arbitrary JSON.

Nexum validates field limits, whole-number quantities, active warehouse ownership, line arithmetic,
totals, and the pinned effective policy before accepting the correction. A successful correction:

- Leaves the immutable source snapshot and source fingerprint unchanged.
- Stores a new immutable repair with method `manual`, the reason, actor, canonical checksum, and
  manual-review evidence tied to the source fingerprint.
- Stores a bounded canonical before snapshot for an exact later diff.
- Replaces only the mutable normalized proposal and synchronized import lines.
- Returns the import to `needs_attention`, ready for controlled reprocessing.
- Does not create or change a Supplier, Item, Purchase Order, receipt, movement, Stock Unit, or
  on-hand quantity.

Manual correction is locked once a Purchase Order exists or the import is terminal.
While an import worker holds the import in processing, Nexum also hides and rejects manual mapping,
Item creation, correction, rejection, and finalization. Every such action rechecks the locked
import row before it writes.

Manual finalization reconstructs both the immutable global policy revision and the import's
checksummed effective policy snapshot. Only the authorized manual actor and the explicit
`register_ordered` outcome are overridden; limits, tolerances, item rules, and other pinned
governance values cannot drift to the current mutable policy.

## Protected Fixtures From Reviewed Imports

On a supplier profile, **Add Protected Fixture from Reviewed Import** creates a regression fixture
without AI. Select a name, a version belonging to that profile, and an import that already has a
validated canonical document. Nexum re-sanitizes the stored source, keeps only a bounded canonical
expectation, records both checksums, and never stores raw mail, raw headers, upload paths, or
credentials in the fixture.

Creating the same fixture source again is idempotent. Nexum runs a fresh protected-fixture replay
against the selected version before reporting success. A failed replay is shown honestly and
remains available for profile correction; it does not produce domain or stock side effects.

## Repair Audit History

The import page presents each immutable manual or AI repair as a bounded audit card. It shows the
persisted repair state beside an operational outcome derived from the current import:

- **Applied** means the import currently contains that corrected document, or the governed
  pre-history correction was applied to both the import and its Purchase Order.
- **Blocked** means a state, permission, supplier, destination, line, shipment, receipt,
  cancellation, or other guard retained the AI result as a proposal without applying it.
- **Superseded** means a newer successful repair replaced the document, or the import no longer
  contains that correction.

New repairs retain a bounded canonical before projection, the corrected after projection,
checksums, actor, reason or diagnosis, validation facts, and display-safe evidence anchors. The
page renders a field-level before/after diff for supplier, order identity, date, currency,
delivery method and expected date, lines, and totals. Evidence is limited to the verified field
path, source or provenance, locator, bounded quote, and source fingerprint. Delivery addresses,
unknown fields, raw messages, headers, prompts, and raw model responses are not rendered in repair
history.

For an AI repair, the same card shows its bounded governed budget decision, limit, spend,
remaining amount, currency, primary workload and provider-reported cost, and secondary-consensus
status and cost when consensus was required. Execution, provider, agent, access-event, and output
checksum identifiers remain available as technical audit facts without exposing model payloads.

AI-created profile candidates show their current, protected-fixture, and historical reproduction
counts and link to the candidate version when the viewer has profile-administration access. An
applied current repair can use the existing controlled retry action when the import is retryable
and has no Purchase Order. A blocked repair can link to its Purchase Order when permitted. Nexum
does not offer a direct apply, reject, or status-edit command for immutable blocked proposals;
operators correct the guard condition and run a new governed repair where appropriate.

Older repair rows created before exact before projections were introduced retain their original
checksum but may not contain detailed before values. The page marks this explicitly. When a prior
repair exists it may show that prior corrected projection only as a labelled legacy fallback,
never as an exact captured before snapshot.


## When AI Is Unavailable

AI is optional. Nexum always tries the active deterministic profile first. AI extraction or profile
repair runs only through the Storage-managed structured workload for the selected Storage agent,
including privacy washing, strict schemas, budget checks and metadata-only telemetry. The agent
cannot execute tools or write directly to Supplier, Item, Purchase Order, receipt, or stock tables.
The managed workload is active only while the policy references it; changing agents or turning AI
off deactivates the old managed path.

Before Integration receives a request, Storage removes payment and contact sections, footers,
remote links, tracking tokens, secret-like values, and instruction-like text that is not order
evidence. Integration then applies the versioned field allowlist and central privacy policy. Raw
mail, unrestricted headers, file paths, credentials, and the original HTML are never part of the
model contract.

Numeric commercial evidence that resembles personal data is replaced with an opaque, request-local
token before an external call. Nexum restores the original value only in memory after receiving a
valid structured response. The external provider never receives the original value, and the token
mapping is not stored.

A policy saved from the ordinary Storage page enables one-sample verified profile activation when AI
is enabled and disables learning when AI is off. Secondary AI consensus and browser-defined provider
cost rules stay off. Nexum owns bounded timeout, output, reasoning, retry, circuit-breaker, and
retention values. A provider failure or invalid response schedules the bounded retry or sends the
import to attention instead of weakening validation or requiring a second workload. Historical
imports pinned to older revisions retain their immutable behavior and audit facts.

If no active Storage agent, provider, model or provider credential is available, the managed
workload is invalid, or the provider is down, Nexum does not send broader data or use an ungoverned
fallback. The effective outage policy either schedules a bounded retry or sends the import to
review with an explainable reason. Admins can still build, test, activate and roll back deterministic
profiles manually. The policy page identifies when no Storage agent is ready.

Every AI result is treated as a proposal. It must match the strict schema, point to source evidence,
and pass the same supplier, Item, arithmetic, currency, warehouse, confidence, and commercial-limit
checks as a deterministic result.

## Permissions

- `storage.purchase_import_view` allows viewing the queue, import details, source snapshots, and
  trace available within Storage.
- `storage.purchase_import_resolve` allows mapping lines and performing permitted resolution work.
  This includes structured manual correction while the import remains mutable.

- `storage.purchase_import_execute` allows retrying, rejecting, and executing permitted import
  actions.
- `storage.purchase_import_profile_manage` allows managing, testing, activating, and rolling back
  supplier profiles. It also gates audited profile-container metadata changes and candidate-version
  links in repair history.
  This includes protected-fixture creation from reviewed imports.

- `storage.purchase_import_policy_manage` allows changing the global automation policy.
- `storage.purchase_manage` remains separately required to create or finalize a Purchase Order.

Opening the original Inbox message also requires `email.inbox_view`. Storage import access does not
grant Email, Signal-rule, Integration-provider, AI-governance, or AI-usage administration access.

## Purchase Order Email Copy

Manual and email-created Purchase Orders use the same operational detail page. Order Details, Order
Lines, Shipments and tracking, Receipt History, and lifecycle actions always come from the canonical
Purchase Order rather than from an import-specific order presentation.

When an order has an email source, users with `storage.purchase_import_view` see one **Email Copy**
card at the bottom of the page after Shipments and Receipt History, with the immutable sanitized
subject, sender, recipients, received time, and message body. The link to the original Inbox message
is shown only while that message exists and the viewer also has Email access. The safe copy remains
available when normal Email retention removes the original.

SPF, DKIM, DMARC, and alignment remain persisted for internal trust decisions but are not displayed
on the operational order or supplier-import detail. Extraction internals, profile versions, policy
snapshots or checksums, and AI repair controls remain on the permission-protected supplier-import
audit page. The Email Copy never embeds remote content and does not expose raw EML, unrestricted
headers, credentials, prompts, or raw model responses.

## Purchase Orders Are Not Receipts

An imported confirmation may create a draft or register an externally placed order as `ordered`.
It does not infer delivery, register a shipment, receive goods, create Stock Units or Movements, or
change on-hand quantity. Automatically created Items also start at zero quantity.

Physical receiving starts from the **Receiving** scope or **Receive** action in the same Supplier
Orders list, but it remains an explicit controlled form described in **Storage Purchase Orders And
Receiving**. Accepted quantities change inventory only when an authorized user posts a goods
receipt. Split and partial deliveries are therefore received one shipment and line quantity at a
time without confusing an order confirmation with arrived stock.

## Retries, Rejections, And Audit

Transient queue or provider failures use bounded retries and backoff. A manual retry uses the same
source and action identity, so a worker crash or repeated Signal action cannot create a second order.
The same supplier order number and identical source is a duplicate no-op. Changed content for the
same order is recorded as a revision or conflict for review, never as an untracked second Purchase
Order.

Permanent authentication, identity, schema, arithmetic, or policy failures do not retry forever.
They enter `needs_attention` or `rejected` with a reason. Manual rejection requires a recorded
reason. Repeated unsafe results can pause only the affected profile through its circuit breaker
while other suppliers continue.

The database permits historical policy and profile versions but enforces at most one current
automation policy and one active version per supplier profile. The policy loader creates the
fail-closed current row when none exists. Profile activation locks the profile before its target
version, supersedes the previous active version, and moves the active-version pointer in one
transaction. Profile-health increments and resets also lock and re-read the profile, so concurrent
failures are not lost and a late success cannot undo a pause or retirement.

Every transition records the stage, result, reason code, attempt number, actor or service identity,
and timestamp. The import also pins its fingerprints, Signal action key, policy revision, profile
version, parser version, and governed AI execution identifier when applicable. Purchase Order
creation, manual resolution, retries, rejection, duplicates, revisions, and later repair remain in
the durable trace.

Attempt rows are append-only audit events: stage start and stage completion are separate rows.
Metadata is minimized and stripped of bodies, unrestricted headers, prompts, and responses before
the row is inserted. Retention maintenance never updates or deletes an attempt, and deleting its
parent import cannot cascade away the trace. Retention therefore applies only to separately eligible
payloads while required attempt evidence remains durable.
Schema rollback is blocked before any guard is removed once separate start/completion history makes
the former one-row-per-stage uniqueness rule impossible to restore safely.

Each scheduled dispatch uses an opaque claim token. Dispatch, worker start, completion, and failure
updates compare that token and the expected current status. A delayed transport exception or failed
job callback cannot release, fail, or complete a claim that a worker already advanced or a later
scheduler claim superseded.
A duplicate queue delivery that observes the same claim already running returns without completing
that worker's dispatch record.

A persistent queue worker is required for active inbound processing. Scheduler-dependent health,
retention, and digest work must also be operational before enabling an automatic mode.
