# Full DataExchange API Verification

Date: 2026-10-25
Status: Done On Dev; automated, native, HTTPS, Swagger and handbook verification complete. Human review Reviewed by Svein Tore; Merged to Main; human review Reviewed by Svein Tore.
Owner: Codex. Human reviewer/release owner: Svein Tore.
Parent: approved RFC 2026-10-25-data-exchange-full-api-parity.md ("Ja godkjenner").
ADR: 2026-10-25-data-exchange-api-commands-and-delivery.md.
Human review: HR-2026-10-25-DATA-EXCHANGE-API, Reviewed by Svein Tore, 2026-10-25; Main promotion authorized.

## Delivered behavior

35 official HTTP operations and 31 explicit typed schemas cover DX01-DX12. The six original URLs
remain; generic export POST now requires reviewed preview_hash and Idempotency-Key. Import commits
require If-Match and Idempotency-Key. See the exact contract JSON and gap matrix.

- Pure scoped context/source/field/filter discovery and paginated profile/run/file/import/audit reads.
- Shared atomic Livewire/API profile composition, strict nested fields, registry validation,
  strong configuration revisions and visible stale-browser feedback.
- Reviewed export commands and durable replay, including exact failed-run results; private files
  are verified on write/download. Browser/API/jobs share the configuration mutex.
- Bounded import review binds input checksum, mapped safe input, normalized rows, profile/mapping
  and source-owned target revision. Locked commit and durable replay prevent repeated writes.
  Provider-authoritative Client creation and the existing Economy owner workflow are retained.
- Shared schedule/target create/read/update/pause, approved references and current operator scope,
  durable unique occurrence claims and frozen scheduled delivery intent before dispatch.
- Delivery preview/dispatch/read/retry/reconciliation freezes exact file/target/destination intent
  before I/O, checks current approving actor/token, and verifies destination bytes. False write,
  exception and unreadable outcomes remain unknown; blind retry is refused.
- Current active actor, explicit PAT grants, registered source/field and Economy owner checks
  apply independently. Operation receipts remain actor-scoped and require original current rights.
- API delivery remains default-off. Unsupported automatic import pickup is explicitly unavailable;
  no new provider connector, arbitrary data source, deletion or production activation is included.

## Files and architecture

Implementation is directly in /var/Projects/tdPSA, branch Dev. No local Windows implementation or production deployment was performed. Shared dirty work is preserved.
Main promotion is verified below; production deployment remains separate.

Exact changed PHP/module file inventory and hashes: 2026-10-25-data-exchange-api-files.json.
Main areas: module-owned routes/controllers, shared Actions/Queries/Support, Livewire profile
builder, Models, schedule/delivery Jobs, typed Resources/Api/V1 contract and regression tests.
Integration changes add only three implemented abilities and scoped API Management documentation.
Client changes add the source-owned import revision callback and preserve mapped input boundaries.
Existing Tripletex service/model implementation is unchanged; Economy's custom-export regression
fixture now supplies the reviewed command inputs.

## Automated verification

- Final DataExchange + Economy + production-mode Swagger: 82 tests / 1011 assertions pass.
  This is after the final shared export mutex/error-boundary/browser-stale validation changes.
- Broader affected DataExchange, Economy, Clients, Workday and production-mode Swagger:
  310 tests / 3233 assertions pass. Sets overlap; do not add these counts.
- Integration API administration: 5 tests / 316 assertions pass.
- Scoped PHP syntax and Pint: 38 files; final evidence in file inventory.
- No known failing test is deferred.

An earlier oversized run also selected unchanged complete Tripletex service suites. It was
intentionally interrupted after the affected DataExchange/Economy/Client sets passed because
provider tests rebuild the entire isolated schema for every case. It is not counted as completed.
The replacement complete affected run above and focused provider-authority/import regression pass.
No new claim of a real provider/time-sync operation is made.

Exact test evidence: 2026-10-25-data-exchange-api-test-evidence.json.
JUnit/log scratch evidence remains outside the repository in /tmp/nexum-dataexchange-api-20261025.

## Native migration and HTTPS

The exact additive migration
database/migrations/2026_10_25_190000_create_data_exchange_command_records.php was applied on Dev,
batch 25. It creates the one-row mutex and empty encrypted receipt/occurrence tables.
Native two-connection lock timeout (1205) and both unique constraints (1062) were exercised in
rollback-only transactions. Existing DataExchange record counts remained unchanged. No live Client,
export, import, target, schedule or provider operation was created.

Nine authenticated trusted HTTPS reads pass; anonymous access returns 401 and a missing record
returns 404. Swagger's actual HTTPS bytes match the generated file and all 35 methods are present.
The process-memory-only temporary token was revoked and original token count restored.
Native/HTTP evidence JSON files contain exact counts/results. No TLS bypass was used.

## Knowledge and BookStack

The source Data Exchange Overview was synchronized through the supported repository Knowledge
publisher; native article 22 is internal/published, source-exact, revision 53. API Management article
3 received only the exact DataExchange section/ability edits, preserving unrelated pending source
differences; it is internal/published, revision 54. Full-source equality is not claimed for that article.

Actual BookStack page 515 revision 5 and page 449 revision 131 were independently read back in
full. Overview content is exact; API Management equals its previous full body plus only the two
scoped edits. Book 339 and chapters 513/374 retain inherited employee access. Editor role 2 maps to
/staff-internal and retains book/chapter/page-view-all. No permission was expanded.
Native pending_push is not external publication evidence. Employee OpenWebUI retrieval remains
a separate untested claim. See knowledge/bookstack evidence JSON.

## Runtime activation and human review

The native installation has zero DataExchange schedules/targets, zero queued DataExchange jobs
and API delivery disabled. A substantial pre-existing shared queue backlog was observed; it was
not drained, cleared or broadly restarted. No live queue/scheduler/provider health is claimed.
Owner: Svein Tore / Dev runtime operator. Before enabling schedules or API delivery, assess the
shared queue, deliberately refresh affected workers, verify the external every-minute scheduler
runner and perform an explicitly controlled local/provider scenario with exact read-back.

No existing runtime was activated. Full API implementation and automated tests do not establish
real external delivery. Frozen unknown outcomes must remain blocked until their destination is
readable and matches. Preserve durable evidence and the app encryption key on rollback.

HR-2026-10-25-DATA-EXCHANGE-API is Reviewed by Svein Tore, 2026-10-25. The retained checklist covers browser/API composition,
scope/denial, import review/commit, schedule occurrence behavior, synthetic delivery and recovery,
Swagger, native migration and handbook. Svein Tore explicitly accepted the delivered scope and authorized Main merge.
Production deployment and controlled runtime activation remain separate.

## Commands for a separately authorized rollout

1. Back up the intended database and retained command/delivery evidence.
2. Apply only the exact additive migration path with --force --no-interaction.
3. Run php artisan l5-swagger:generate and php artisan route:clear; refresh configuration as needed.
4. Deliberately refresh queue workers after runtime preflight; confirm the external scheduler runner.
5. Configure only approved DATA_EXCHANGE_DELIVERY_DISKS / DATA_EXCHANGE_CREDENTIAL_REFERENCES;
   leave DATA_EXCHANGE_API_DELIVERY_ENABLED=false until controlled verification is authorized.
6. Read back HTTP/Swagger, scoped Knowledge/BookStack and intended exact workflow results.

No frontend asset build is required. Do not run unrelated pending migrations or a blanket handbook
push. Existing files, old receipts and occurrence/delivery evidence must survive rollback.

## Isolated Main candidate verification

Candidate base: 95c10a422459b0b7c440deb86ccc39085a3f4bbc. Branch: codex/data-exchange-api-20261025.
The scoped candidate on the Dev server passes 308 affected tests / 3214 assertions and
5 API-administration tests / 257 assertions, with no failures, errors or skips. These differ from
shared-Dev totals because unrelated unmerged routes/workflows were excluded deliberately.
All 38 scoped PHP syntax/Pint checks and generation of all 35 Swagger operations pass.
The shared ability catalog contains only six added DataExchange grant/metadata lines.
No unrelated dirty Dev work, .env, vendor, runtime storage or frontend build files enter the candidate.
The repository-tracked Swagger JSON is regenerated in production scan mode from this exact candidate.
See 2026-10-25-data-exchange-api-main-candidate-evidence.json. Human approval is recorded;
production deployment and runtime activation remain separate.

## Verified Main merge

Main merge verified: [PR #317](https://github.com/SveinT83/Nexum-PSA/pull/317), commit
9090026f2172da0dfacc188132a5195156e8df45. The merged tree equals tested candidate
8eda27d3567eef5f62dc4eaadcc5e87064f92473 (tree e8962d18d6eee887783970363148060c305ecd14).
Production deployment and runtime activation were not performed.
