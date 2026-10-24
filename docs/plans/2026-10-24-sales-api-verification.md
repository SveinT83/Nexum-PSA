# Full Sales API verification on Dev

Status: Merged to Main / Human Reviewed; production pending.
Date: 2026-10-24. Implementation: Codex. Human reviewer/release owner: Svein Tore.
Approval: Svein Tore approved the target RFC in chat with "Godkjenner".
Final human acceptance was separately granted by Svein Tore on 2026-10-25.

## Delivered behavior

All 53 browser routes (52 controller operations and one compatibility redirect)
have implemented API counterparts. The frozen contract contains 75 HTTP operations:
62 staff and 13 public/portal operations. This completes the approved current Sales
scope, including leads/classification/start; client/contact owner bridges;
opportunities/stakeholders/activities/read acknowledgements; quote drafts, CPQ lines,
templates, versions, approvals, revision, customer previews/PDFs; frozen email
previews/sends; public-token and authenticated portal responses; acceptance snapshots,
conversion-plan status/reference, settings, reports and delivery operation read-back.
This is Dev implementation evidence; named human acceptance was granted on 2026-10-25.

Browser and API controllers share business actions and PDF rendering. Existing
pricing/cadence, approval invalidation, immutable sent/accepted history, Ticket
fulfilment and Calendar follow-up ownership are preserved. No new downstream
conversion engine or unsupported opportunity/quote deletion was introduced.

Staff requests require both token ability and current active user/domain authority.
Admin settings use the existing administrative policy. Parent/contact/catalog and
portal company/site boundaries remain enforced. Public tokens retain exact quote
capability authority. Customer resources exclude private cost, approval, provider,
notes and storage metadata; staff cannot impersonate portal acceptance.

API GET/context/PDF requests are pure. Explicit setup/viewed commands handle
initialization and lifecycle. Mutation fields are closed and typed, including nested
selections; omitted draft metadata remains unchanged. Strong revision hashes and
locked durable command receipts prevent stale writes and duplicate effects. New
commands require Idempotency-Key; legacy optional-key routes retain compatibility.

Delivery attempts encrypt frozen recipient/content/provider metadata. Queue dispatch
occurs after commit, and jobs atomically claim each attempt once. Queued, processing,
SMTP accepted, failed, unknown and no-recipient states remain distinct. Unknown SMTP
outcomes are not automatically retried. SMTP acceptance is not inbox delivery.

## Automated verification

- Existing pre-change Sales baseline: 29 tests / 453 assertions passed.
- Final complete Sales suite: **50 tests / 2025 assertions passed**, 78.25 seconds.
  Includes all existing browser regressions, full 75-operation route/schema contract,
  current/revoked authority, strict inputs, stale/replay/delete behavior, frozen SMTP,
  no-recipient evidence, provider-intent recovery and real portal identity fixtures.
- An intermediate run found a missing approval-policy model import; corrected before
  the final green run. No known failed Sales verification is deferred.
- All 49 scoped PHP files pass php -l and scoped Pint --test.
- Broad affected suite: **433 tests / 5407 assertions passed**, 1550.05 seconds.
  Sales, Ticket module/workflow/lifecycle, Storage module, Client, Contact,
  Customer Portal quote/commercial economy, Integration module/Swagger production
  scan, Tripletex customer numbering and profile. This run overlaps the Sales suite;
  the final 50-test Sales rerun verifies the last policy/import/input refinements.
  No full repository suite was run across the unrelated shared dirty work.
- Native MySQL two-connection probe passed: lock timeout 1205 while another writer
  held the intent; duplicate 1062 after commit; exactly one row. Scratch table removed.
  Isolated SQLite tests cover command/action behavior. No live customer/provider send.

Contract: `app/Modules/Sales/Docs/api-contract.json` (75 operations).
Schemas: `app/Modules/Sales/Docs/api-schemas.json` (95 schemas).
Matrix: [all browser mappings](2026-10-24-sales-api-gap-matrix.md).
Scoped source hashes: [changed files](2026-10-24-sales-api-files.json).
Historical inventory is retained in `2026-10-24-sales-api-inventory.json`.

## Native Dev read-back

Authoritative checkout: /var/Projects/tdPSA, branch Dev. Pre-existing shared dirty
work was preserved; no commit, push, Main promotion or production deployment.
Migration `2026_10_24_170000_create_sales_api_command_and_delivery_records.php`
was applied by its exact path only and read back as batch 22. Receipt table has seven
columns and zero rows; delivery-attempt table has 17 columns and zero rows.

Trusted HTTPS authenticated GETs to Sales context and opportunity listing return
200 with JSON. The temporary narrow Dev test token was revoked in finally and the
original token count retained. Health /up returns 200. No credentials were printed.
All new PHP files are web-readable (0644); Sales directories follow existing access.

Swagger regenerated with php artisan l5-swagger:generate. GET https://dev.nexumpsa.eu/docs
returned exact generated bytes through trusted TLS. All 75 Sales method/path pairs
are present; SHA-256 59139643633cad2532a2ba664898e6a0978a150359b5bf94cb96adb420a2c3bf9.
Route cache was cleared and graceful queue restart signalled after final code checks.
No frontend build or scheduler recovery job is required by this change. Restart signal
alone is not a claim that a real delivery job was processed.

## Employee documentation verification

Authoritative Sales API/overview and Integration API Management source docs updated.
Sales-only knowledge:sync-docs synchronized two articles. Native Knowledge IDs 11/12
match exact trimmed source, visibility internal, status published. Their local sync
status remains pending_push; verified BookStack publication below was performed through
the connected BookStack integration. No global push of unrelated pending docs occurred.

BookStack pages read back after publication:

| Page | Revision | Verification |
|---|---:|---|
| [Sales API 508](https://doc.tronderdata.no/link/508) | 8 | Full new source matches |
| [Sales Overview 428](https://doc.tronderdata.no/link/428) | 8 | Full source matches |
| [API Management 449](https://doc.tronderdata.no/link/449) | 122 | Only own Sales section appended; unrelated older content preserved |
| [Historical Sales API 507](https://doc.tronderdata.no/link/507) | 2 | Explicit historical pointer to 508; old content retained |

Published pages and parents inherit permissions; Editor staff role has book/chapter/
page view rights and external group /staff-internal. No access settings were changed.
Employee OpenWebUI retrieval and actual inbox receipt are separate checks and are not
claimed. Every new section clearly distinguishes Dev from production and names the
human-review record. Website editorial handoff is prepared with an explicit do-not-
publish restriction until reviewed and production availability is verified.

## Human checks and rollout

Human-review checklist **HR-2026-10-24-SALES-API** is Reviewed. Svein Tore approved
the delivered scope and Main merge on 2026-10-25. Individual scenario checks are
retained as history; no unreported execution is inferred. Production deployment and
real provider/message activation still require a separate request. No real customer messages, portal acceptance, provider writes or stock
changes were performed in the native Dev database by verification.

Main/production rollout, if separately authorized after review:

1. Promote only the reviewed scoped delta; preserve unrelated Dev work.
2. Back up database and application configuration, retain the existing app key for
   encrypted delivery evidence, and apply only the additive Sales migration:
   `php artisan migrate --path=database/migrations/2026_10_24_170000_create_sales_api_command_and_delivery_records.php --force`.
3. Generate Swagger: `php artisan l5-swagger:generate`; clear affected route cache:
   `php artisan route:clear`; signal workers: `php artisan queue:restart`.
4. Verify trusted HTTPS, exact route/schema read-back, worker health and current
   roles/provider settings. Grant narrow API abilities only through normal admin policy.
5. Perform a separately scoped controlled message test and confirm the target mailbox
   before claiming receipt. Provider unknown outcomes require reconciliation.

Operational rollback disables the new entry points/dispatch and keeps receipts,
attempts, accepted snapshots and sent evidence. Migration down refuses to erase
operational evidence. Do not blind-retry unknown delivery or provider intents.

## Human acceptance (2026-10-25)

Svein Tore approved the delivered scope and Main merge in chat: "Godkjent. Merg til main".
HR-2026-10-24-SALES-API is Reviewed. The earlier pending-review wording records the
2026-10-24 implementation handoff; this acceptance clears the Main merge gate.
Production deployment, provider activation and real message delivery remain separate.

Main implementation merge independently verified on 2026-10-25: [PR #311](https://github.com/SveinT83/Nexum-PSA/pull/311),
commit `81812c5b8989dcb3522245b87e69390fb57a2141`. GitHub and origin/main agree; the complete merge tree
equals the tested final candidate e5d4c989abc8bf64a39235d83672bcce696f0f17.
Dev HEAD/index and unrelated work are preserved. Production deployment and activation
were not performed.
