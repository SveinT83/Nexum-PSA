# Commercial API verification - 25 October 2026

Status: Merged to Main / Human Reviewed (Svein Tore, 2026-10-25).
Implementation owner: Codex. Human reviewer and release owner: Svein Tore.
Approved scope: full Commercial web/API parity, not a read-only or metadata-only slice.
Authoritative checkout: /var/Projects/tdPSA, branch Dev, original/current HEAD
36918edbcaae1ce7b1208ba34f84a3a1719f0e98. Shared dirty Dev work preserved; subsequent Main promotion is recorded below.

## Implemented behavior

The API has 103 HTTP operations excluding HEAD: 101 supported staff/public/portal operations and the
two pre-existing separately controlled coordinator exports. All 18 workflow rows map to current
operations; the unused dead SLA delete route is removed. Unit deletion is supported and retained.
Forms/aliases map to context and canonical commands rather than redundant no-op APIs.

Typed catalogues cover Service metadata/cost/legal/rate composition, Packages and inherited terms,
Costs, versioned legal documents, SLA/default behavior, Time Rates, Units, filters/sorts and bounded
selection context. Active provider ownership and in-use legal protections remain.
Contract operations cover metadata, the existing responsible-Technician choice and form date defaults,
source/offer defaults, parent-scoped lines/rate snapshots,
cadence adoption, exact pricing plus staff editor cost/discount/profit preview, terms review/refresh,
manual approval, named legacy attestation and shared immutable customer document/PDF.
Public/portal reads and acceptance retain their distinct exact capability/session identities.
Operational Client timebank covers eligible periods/rates/balances, policy, locked registration,
rate/usage snapshots and safe retries; existing pseudonymous exports remain separate.

Browser, Livewire and API use shared domain actions. Current active actor/domain rights and token
ceilings are independently enforced. Strict nested inputs reject provider/actor/evidence injection.
New mutations require current If-Match and a durable Idempotency-Key; existing metadata routes
retain optional-key compatibility. Replays reauthorize before returning protected durable results.
Deletes retain receipts. Unknown SMTP attempts are not blindly retried.

Durable delivery freezes reviewed recipient/CC/body/document/provider binding in encrypted metadata,
submits after commit and atomically claims once. States distinguish no recipient, queue/processing,
SMTP acceptance, preflight review and unknown outcomes. Read-back includes provider submission,
recovery eligibility and stale-processing reconciliation flags; inbox delivery is never inferred.
API delivery and browser durable rollout remain default-off pending schema/worker/native review.
The normal browser transport remains unchanged until its explicit rollout switch is enabled.

## Automated verification

- Original baseline: 95 Commercial tests / 1387 assertions passed.
- Final complete Commercial plus affected portal suite: **129 tests / 1898 assertions passed**,
  88.82 seconds, 145 MB. Includes 29 new parity tests, original Commercial regressions and five
  portal Commercial/acceptance cases. Real bearer/current/revoked user rights, token ceilings,
  strict nesting, composition/PATCH preservation, protected immutable documents, exact money,
  stale/replay/delete semantics, public rotation, portal Client/membership/legal/audit/notification,
  real CSRF refusal, timebank/policy, PDF/legacy attestation, default-off delivery, once-only SMTP
  accepted/unknown claim, browser shared delivery and full route/schema/new-web-inventory checks.
- Broader affected Economy, Integration/API administration, production Swagger scan, Clients and
  scheduled Ticket SLA suite: **113 tests / 1104 assertions passed**, 146.15 seconds, 135 MB.
  Four existing ScheduledTicketSlaTest PHPDoc @test metadata deprecation warnings remain.
  These are warnings, not failing or skipped tests. No failing test has been deferred.
- Final php -l and Pint --test: **68 scoped PHP files pass**.
- Swagger production-scan regression passes within the broader suite. Complete source scan also
  regenerated successfully with isolated test configuration to avoid the unavailable native DB.
- No full repository suite across unrelated shared dirty work was run. No live customer/provider
  message, acceptance, stock, billing or account change was used for verification.

Earlier runs found two API compatibility regressions (Service integration-managed field alias and
existing binding-date message) and a Unit/Livewire validation regression; all were fixed.
New fixture failures were corrected without weakening assertions or supported domain guards.
The existing web created_by field is the Technician assignment, not approval actor evidence.
Its choice and eligible lookup/default dates are shared by web and API and covered by an explicit
selected-technician comparison. Generated Service rate and Package price dictionaries use typed
object maps, so generated clients can send the same selections. A named test-only CSRF helper
avoids an anonymous-class formatter oscillation; all final style checks are required to pass.
Final scope review added regressions for deleting Units through the update action and for revision
hashes immediately after creation. Unit delete requires the delete ability before replay/preconditions;
revisions include persisted database defaults. Both focused regressions pass (2 tests / 15 assertions).
Own-operation read-back also works with only the original quick-consume permission; it does not
demand an unrelated Client-view or catalogue permission. The original operation is reauthorized.

## Contract and handbook artifacts

- app/Modules/Commercial/Docs/api-contract.json: 103 exact method/path contracts.
- app/Modules/Commercial/Docs/api-schemas.json: 47 typed Commercial schemas.
- docs/plans/2026-10-25-commercial-api-current-coverage.json: current workflow mapping.
- docs/plans/2026-10-25-commercial-api-endpoints.json: staff/customer route/default manifest.
- docs/plans/2026-10-25-commercial-api-files.json: scoped final source hashes.
- Original inventory and gap matrix retain pre-change route/field/source evidence.

Knowledge source docs for API, Contracts, Services, Packages, Costs, SLA and rates were updated;
Integration API Management has a scoped additive section. BookStack pages were independently
published and read back with exact source or exact appended section:

| Page | Revision |
| --- | ---: |
| Commercial API 457 | 13 |
| Contract System 380 | 14 |
| Cost Catalogue 389 | 14 |
| Package Catalogue 396 | 14 |
| Service Catalogue 401 | 15 |
| SLA Inheritance 406 | 15 |
| Time Rates 410 | 15 |
| API Management 449 | 128 |

Pages and chapters inherit from book 339. Staff Editor role 2 maps to /staff-internal and has
book/chapter/page view-all. No permissions were expanded. Machine evidence is in
commercial-api-handbook-evidence.json. Commercial-only knowledge:sync-docs updated eight native
articles (IDs 13-20), all read back as exact source, internal and published. Their pending_push
metadata is not external publication evidence; BookStack above was verified independently.
Only the scoped Commercial section changed in BookStack API Management. Its larger repository
document contains pre-existing unpublished differences; no blanket Integration sync/push or
overwrite of those differences occurred. Employee OpenWebUI retrieval was not tested.

## Native migration, concurrency and HTTP read-back

The earlier MySQL/Plesk outage recovered during this session. No physical-server reset or blind
console key was sent. Strict-key Dev access stayed available; the backend later answered TCP/MySQL.
The exact additive migration applied as batch 23 and was independently read back:
commercial_command_receipts has 7 columns, commercial_delivery_attempts has 15; both have zero rows.
The actor/operation/key unique index exists. No historical Contract/customer rows were modified.

A native scratch table copied the actual receipt schema. Two independent connections demonstrated
lock timeout 1205 while the first held the row and unique refusal 1062 after commit; exactly one
synthetic row remained. Both transactions ended and the scratch table was dropped/read back absent.

Trusted authenticated HTTPS returned 200 with data for Service context/list, Contract context/Technician lookup/list,
Units context and timebank policy. A temporary read-only scoped token was created in memory and
revoked in finally; the original token count was restored. No token or response/customer body was
printed. There was no eligible existing immutable snapshot among ten bounded PDF candidates;
native customer PDF was not fabricated. Isolated HTTP/PDF/legacy tests pass; native visual PDF review
remains in the human checklist.

Normal native l5-swagger:generate completed; route cache was cleared. Trusted HTTPS GET
https://dev.nexumpsa.eu/docs returned HTTP 200 and the exact generated bytes.
SHA-256: 2a0bc6f2d5285eef7be925f65463ee8b38eed6c9da4a85d0c7e14898352a9c67.
Anonymous Commercial services GET returned 401. /up returned 200; it was not treated as DB proof.
New directories are 2755 and files 0644, web-readable. Native evidence is in
commercial-api-native-evidence.json and commercial-api-knowledge-evidence.json.

Eight queue:work processes were observed. Both COMMERCIAL_API_DELIVERY_ENABLED and
COMMERCIAL_DURABLE_DELIVERY_ENABLED read back false. No real delivery was queued, no shared worker
restart was forced and no provider or inbox outcome is claimed. Controlled activation remains a
human-review/operating choice, not a missing endpoint.

## Recovery during this implementation

An earlier self-include in the first API route loader recursed and exhausted Dev VM memory.
The initial external-outage assumption was corrected after process evidence identified the cause.
Svein authorized VM132 recovery. Its exact stopped disk was repaired offline, cleanly unmounted and
restarted. The route loader now has mutually exclusive direct branches and no self-include.
All subsequent route/test runs are bounded by memory/time limits; final suite peaks at 145 MB.
No hypervisor/other guest reset occurred. Temporary recovery files are outside the repository.

## Main/production and controlled activation

The following is for separately approved promotion, not commands still missing on Dev:

1. Preserve the intended environment's database backup, then apply only
   database/migrations/2026_10_25_120000_create_commercial_command_and_delivery_records.php.
2. Read back migration, unique receipt index and both schemas/row counts.
3. Generate Swagger and compare trusted HTTPS exact bytes; clear routes and smoke-test authorized
   context and browser workflows. Native Dev checks above are already complete.
4. Sync only the reviewed Commercial Knowledge sources. Do not blanket-push unrelated pending
   Integration changes; reconcile only the scoped API Management section.
5. Keep both delivery switches off until controlled activation checks pass. Inspect the target's active
   worker/provider configuration, enable deliberately, restart workers for reviewed activation
   and verify a named synthetic recipient before any real-message claim.
6. HR-2026-10-25-COMMERCIAL-API is Reviewed by Svein Tore (2026-10-25). Svein owns separately authorized promotion.

No frontend build, new scheduler job or migration of existing Contract history is required.
Rollback disables the rollout switches and restores scoped application code; preserve receipts,
delivery payloads and the app encryption key. Migration down refuses deletion when evidence exists.

## Human review and public handoff

HR-2026-10-25-COMMERCIAL-API is **Reviewed** by Svein Tore on 2026-10-25. His "Godkjent"
followed the completed implementation and verification handoff and accepts the delivered scope.
The original six scenarios remain in the register as history; individual execution is not inferred.
This clears the Commercial human-review gate. Main promotion, production deployment and controlled
delivery activation remain separate actions. Real provider delivery and employee-assistant retrieval
remain distinct verification claims. This approval update changes documentation only; tests were not
rerun because application behavior is unchanged.

One public-safe Commercial item was appended to the agreed editorial handoff, read back exactly as
UTF-8, status klar for vurdering. Heading count increased 6 to 7. Its do-not-publish gate requires
production rollout and verified availability; human review is now recorded; it makes no production or MCP claim.
Main source promotion is verified below. No production deployment, real customer send or runtime activation occurred.

## Verified Main promotion - 25 October 2026

Svein Tore requested "Merge" after approving the delivered scope. [PR #313](https://github.com/SveinT83/Nexum-PSA/pull/313)
merged as `d1e688a2ff6e794dd15629db89d720405548dce1`. GitHub and origin/main agree; the complete merge
tree equals tested candidate `a21965de761aa85f883aea8005af92399dc3ada1`. Fresh candidate tests:
145 Commercial/Portal / 2040 assertions and 356 affected / 4880 assertions passed. All 68
PHP files pass syntax/Pint. Non-Commercial Swagger paths/schemas/security/tags are unchanged.
See commercial-api-main-promotion.md and commercial-api-main-evidence.json.
Production deployment, migrations, worker refresh and controlled activation are not included.
