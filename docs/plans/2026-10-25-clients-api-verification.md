# Clients API verification and handoff

Status: Implemented and verified on Dev; human Reviewed / Main promotion authorized.
Date: 2026-10-25. Implementer: Codex. Reviewer/release owner: Svein Tore.
Human-review checklist: HR-2026-10-25-CLIENTS-API.
The human-review gate is cleared. Main promotion is authorized; production
migration/deployment and activation require separate authorization.
The earlier "Ja" approves the design and Dev implementation; it is not acceptance.

## Result and scope

All C01-C20 in the approved RFC are implemented or explicitly mapped to their
verified authoritative owner API. The inventory covers 37 browser route rows and
Notes Livewire behavior. The Clients module now owns 51 HTTP operations and 74
exact typed schemas. Existing canonical Contact, Task-template and Commercial
timebank APIs remain authoritative; 17 reused method/path pairs were also checked
against registered routes. A method count is not the definition of workflow parity.

The operation catalogue records each exact method, route, operation ID, request,
response and scope. The verification JSON maps every C01-C20 row to operation IDs,
reused owner APIs and actual test names. Both are checked against implementation.

- Contract: 2026-10-25-clients-api-contract.json.
- Exact evidence and changed-file hashes: 2026-10-25-clients-api-verification.json.
- Browser baseline: 2026-10-25-clients-api-route-baseline.json.
- Accountability: 2026-10-25-clients-api-gap-matrix.md.
- Approved RFC: ../rfc/2026-10-25-clients-full-api-parity.md.
- Accepted architecture: ../adr/2026-10-25-clients-api-commands-and-provider-intent.md.

## Implemented behavior and files

Domain APIs are registered in app/Modules/Clients/routes.php; api.php is a
compatibility loader and routes/api.php loads the session/CSRF portal group.
The legacy module name and browser URLs remain in place.

The Client API controller/resources now support full editable-field read-back,
filter/sort/context, atomic default Site and optional canonical primary Contact.
Dedicated workspace, related-resource, operations, time, provider and licence
controllers expose supported contexts, writes and actions with current authority.
ApiAbilityCatalog adds only matching operation scopes; existing tokens are not widened.

Shared Actions implement settings, Notes, Site writes/deletion, Format settings,
time corrections and staff/portal licence workflows for browser/API entry points.
Source time corrections use the Economy source lock and retain ordered/coupled
billing restrictions. Site deletion refuses historical, portal, provider and
other owner references, including legacy cascade/nulling references.

Current actor, token, parent, object, field and portal membership are rechecked.
Strict inputs and content revisions prevent silent ignored fields and stale edits.
Durable actor/token/payload-bound commands persist external intent before transport,
separate local success from provider outcomes and stop blind retry after uncertainty.
Tripletex retains provider number authority and explicit invoice/address intent:
omitted addresses do not trigger a profile fetch or change legacy request hashes.

New durable tables are created by
database/migrations/2026_10_25_220000_create_client_api_command_records.php.
config/clients-api.php keeps provider API writes default-off.
The exact application/test file list and SHA-256 hashes are in the evidence JSON.
Unrelated dirty work was preserved; no commit, push, Main merge or production deploy occurred.

## Automated verification

Commands ran on authoritative Dev with the existing PHP/vendor/runtime and
umask 0002; Laravel tests used HOME=/tmp. Runs overlap; do not add test counts.

| Run | Result and interpretation |
| --- | --- |
| Baseline Clients/CustomField/Contact | 100 passed, 779 assertions before implementation |
| Broad affected regression | 537 passed, 2 failed, 5,866 assertions; both failures were the same newly introduced unconditional Tripletex Site payload bug |
| Corrected invoice-email API creation | 1 passed, 5 assertions |
| Corrected Tripletex create/update authority | 1 passed, 6 assertions |
| Final core contracts/parity/CustomField/portal | 30 passed, 637 assertions |
| Independent committed provider-intent scenarios | 6 passed, 53 assertions; fake external transport, no enclosing rollback-only test transaction |
| Staff quantity/renewal/status and foreign integration | 1 passed, 21 assertions |
| Explicit Tripletex address/profile and exact Site binding | 1 passed, 13 assertions |
| PHP syntax and Pint | All 46 changed PHP files passed |
| Scoped git diff check | Passed |

The broad run covered Clients, CustomField, Contact, Commercial, Economy, Task,
CustomerPortal, CloudFactory, Tripletex number/profile and Integration administration.
Both broad failures were fixed and individually rerun, followed by final affected
contract checks. The entire broad suite was not rerun after the fix. There is no
known unresolved failing verification check. Logs are outside the repository in
/tmp/nexum-clients-api-20261025; machine-readable evidence records exact log names.

## Native database and trusted HTTPS

The exact additive migration was applied only to Dev, migration batch 26.
Two independent MySQL connections proved the shared row lock (lock timeout 1205).
Rollback-only duplicate probes proved command and RMM uniqueness (1062).
No native customer or provider write was used to validate mutation workflows.

A temporary scoped personal token exercised 12 real trusted HTTPS read endpoints:
Client list/detail/context/settings/workspace, Sites/detail/context, Formats,
licences/context and time usage. All returned JSON 200. Anonymous access returned
401; a missing Client returned 404. The token was deleted in a finally block.

Swagger was regenerated outside PHPUnit in the native runtime. The actual HTTPS
document matched the generated file byte-for-byte and contained all 51 exact
operation IDs. Native Client, Site, token, command and RMM-intent counts were
unchanged after verification. The new provider API write switch remained false.
Real provider writes, external MCP catalogue publication, Main and production
availability were not exercised or claimed.

## Knowledge and employee handbook

Current Clients source articles were synced into native Knowledge and read back
with exact source bodies, internal visibility and published status. The scoped
Clients section was added to the existing API Management article without replacing
unrelated content. Native automatic BookStack integration is unavailable and
records remain pending_push; that pre-existing configuration was not enabled.

Publication instead used the existing documented personal BookStack credential
path, with preflight and current-revision checks. The connector's write schema
rejected nonempty fields before writing; the personal API path then completed
publication. An independent connector read verified all actual published pages:

- [Clients API Support](https://doc.tronderdata.no/books/nexum-psa/page/clients-api-support):
  page 639, revision 1, complete exact normalized source, draft=false.
- [Client Domain Overview](https://doc.tronderdata.no/books/nexum-psa/page/client-domain-overview):
  page 451, revision 10, complete exact normalized source, draft=false.
- [API Management](https://doc.tronderdata.no/books/nexum-psa/page/api-management):
  page 449, revision 132, exact scoped section present, draft=false.

Rendered HTML was present. Pages and chapters inherit the book's existing access;
the staff-internal Editor role already has book/page view rights. No permission
was changed. Employee OpenWebUI retrieval is a separate manual check and was not tested.
Do not confuse direct verified publication with enabling the native sync worker.

## Deployment and recovery

No new build assets, queue class, scheduler registration or recurring job is introduced.
Existing provider integrations and their workers retain their own operating requirements.

After named human review and separately authorized promotion, the release owner should:

1. Preserve the candidate and existing shared changes, and back up the target database.
2. Apply only the exact additive migration path if not already recorded:
   php artisan migrate --path=database/migrations/2026_10_25_220000_create_client_api_command_records.php --force.
3. Clear/rebuild the target's existing Laravel caches as its normal release requires
   (php artisan optimize:clear) and run php artisan l5-swagger:generate.
4. Verify trusted HTTPS routes, authentication, exact Swagger, durable tables and
   unchanged provider switches on that target before declaring availability.
5. Consider provider API activation only as a separate authorized controlled scenario.

The first recovery step is to disable new API provider writes and preserve all
command, provider, legal and financial evidence. Migration rollback refuses to
drop nonempty receipt/intent tables. Do not blindly retry uncertain provider effects
or delete receipts/encryption material to clear a conflict.

## Human approval and promotion

Svein Tore explicitly accepted the completed delivery: "Godkjent. Merge til main".
HR-2026-10-25-CLIENTS-API is Reviewed. The checklist below is retained as historical
review scope without claiming individually witnessed scenario execution.
The scoped Main candidate is being verified separately. See
2026-10-25-clients-api-main-promotion.md for current promotion status.
Production deployment, live provider writes/activation and external MCP publication
remain separate and were not requested.

### Concrete review checks (from the authoritative human-review entry)

- [ ] Compare Client filters/sort, create/edit context and every editable field in
  browser/API; exact read-back includes Notes/website/format/status. GET has no writes.
- [ ] Create a Client/default Site/primary Contact; verify canonical relation and
  stable bridge, validation rollback, duplicate/replay behavior and number authority.
- [ ] Exercise settings/RMM mapping and Notes as distinct rights; verify partial or
  unknown provider outcomes and blocked writes while disabled. Real writes need scope.
- [ ] Create/edit/read/delete a disposable Site; verify default, field constraints,
  custom-field visibility and protection of historical/provider-bound references.
- [ ] Compare authorized Contacts, Assets, Tickets, Tasks, Contracts, Signals, Risk,
  template application and timebank links/counts. Denied data remains unavailable.
- [ ] Correct synthetic quick/Ticket/Task time; validate ordered/coupled denial,
  source ownership, rates/allocation, revisions and concurrent Economy source lock.
- [ ] Review fake staff/portal licence actions, contract/terms/legal acceptance,
  current Customer admin scope, submitted versus confirmed and reconciliation.
- [ ] Create/update/disable a Client Format under current admin/settings authority;
  no unsupported delete. Confirm actor/token/field/foreign-parent denial scenarios.
- [ ] Inspect complete typed Swagger and operation-to-workflow/test mapping,
  authoritative Dev evidence, Knowledge and actual BookStack content/access.

Promotion authorization: Svein Tore explicitly accepted the completed delivery on
2026-10-25: "Godkjent. Merge til main". HR-2026-10-25-CLIENTS-API is Reviewed.
The review/checklist statements above describe the preceding Dev handoff; Main
promotion evidence is recorded in 2026-10-25-clients-api-main-promotion.md.
Production deployment and provider activation are not authorized by this approval.
