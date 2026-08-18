# Feature Slice: Documentation And File-Provider Attachment Save/Link

Status: Queued / Dependency Gated
Date: 2026-08-16
Level: 3
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
ADR: `docs/adr/2026-08-16-documentation-file-provider-handoff.md`
Owners: Documentation / Nextcloud / Email / Integration
Human Review: `HR-2026-08-16-030`

## Goal

Let an authorized technician copy a currently clean Email attachment into a durable Documentation-
owned file through an approved provider and explicitly link that file to an authorized Documentation/
Work Context record. Preserve provenance without turning either relationship into cross-domain access
or implicit deletion authority.

## Dependencies

Orders 5, 8, 13, 19, 21 and 23 must be stable: canonical/source identity, private invalidation,
Ticket relationships/audience, attachment quarantine and lifecycle/hold/export. The selected
Nextcloud connection must have reviewed write capability, endpoint/credential security and target
folder mappings. No handoff works from raw/risk-download or non-clean evidence.

## Additive Data Model

Reserve migrations `2026_08_16_172000` and `173000`.

Add Documentation-owned files/links:

- `documentation_files`: opaque UUID, Work Context/client/site owner, provider connection/object/folder
  identity, safe filename/MIME/size/SHA-256, provider version/ETag, source kind, current status,
  lifecycle/hold/security evidence and creator timestamps;
- `documentation_file_links`: file plus exact Documentation/WorkContext/Ticket-compatible target,
  link role, actor/reason/audience and unlink history; target type is an explicit allowlist;
- append-only file/provider events for version, move, unavailable, delete and access/lifecycle state.

Add Email handoff runs/items:

- exact source conversation/message/placement/attachment/account and canonical/source fingerprints;
- actor, target Work Context/document, provider connection/folder, normalized destination filename;
- source clean policy/evidence/hash/size, target authorization/provider capability/version fingerprint;
- states `previewed`, `queued`, `uploading`, `provider_write_started`, `uploaded_unverified`,
  `completed`, `unresolved`, `blocked`, `stale`, `cancelled` or `failed_pre_write`;
- tokenized claim/upload identity, bytes/chunks/progress, safe reason/error and final Documentation file
  reference. No body, source address, provider response, credential or private path.

Unique idempotency covers exact source hash+target folder+filename+intent; a different target/scope
conflicts. Down refuses while files/links/runs/provider evidence depend on schema. Migration copies/
uploads/links/deletes nothing.

## User Flow And Preview

From the selected message attachment, **Save to documentation** opens a Bootstrap modal/wizard:

1. reauthorize exact current source placement and attachment;
2. require exact current `clean` scanner evidence/hash/bytes;
3. choose only authorized internal/client/site Work Context and optionally existing Documentation;
4. choose an eligible active write provider/folder mapping, never arbitrary path/URL;
5. show sanitized filename, size/type/checksum short ID, destination, conflict/version policy,
   provenance and who will gain Documentation access; and
6. require an exact unexpired confirmation before queueing.

Default filename preserves a sanitized display name, but collision never silently overwrites. The
actor chooses cancel, deterministic suffix/new file or an explicitly permissioned new version after
seeing current target metadata. Existing remote files cannot be linked by pasting a URL/path; a
provider-owned permission-scoped browser/import preview is required.

After successful copy, the source Mail card shows an authorized safe link to the Documentation file.
Users without target authority see no target existence. Documentation displays source provenance only
when the viewer also has current Mail access; otherwise it shows that it was imported from Email with
safe date/actor, not mailbox/message/content metadata.

## Upload And Reconciliation

One queue job handles one item under source/file/provider locks. Recheck Mail, clean hash/file bytes,
target Work Context/document, provider binding/folder/capability and exact preview. Stream bytes with
bounded memory/deadline while recomputing SHA-256; any drift blocks before provider write.

Persist `provider_write_started` before PUT/chunk. Small file uses no-overwrite PUT. Large file uses a
token-owned chunk v2 directory and bounded chunks, then MOVE assembly. After any successful response,
PROPFIND/read metadata proves exact provider file ID/path/ETag/size/checksum. Only then create the
Documentation file/link and complete the handoff transactionally/idempotently.

Failure proven before provider write is retryable under bounded lease. Timeout/connection/lost
response after possible write is `unresolved`; no second upload. A reconciliation job inspects the
exact intended provider object and completes only when checksum/size/upload identity prove it. A
different/missing object remains unresolved/conflict. Cancellation is allowed before provider write;
afterward it requests reconciliation, not deletion.

For chunk failures, safe cleanup targets only the exact owned upload directory and only after proving
no final object/active worker. Provider quota/lock/virus/version conflicts are stable visible states.

## Link, Version And Lifecycle

Linking an existing `DocumentationFile` to another record reauthorizes source file plus target and
creates only the relationship. It never copies bytes, changes provider ACL/share, publishes to portal
or changes Email/Ticket audience. New version uploads preserve immutable prior version/provenance and
require explicit confirmation; source Email changes do not mutate an existing durable copy.

Unlink removes one relationship after policy checks and preserves file/event evidence. File delete/
provider trash follows Documentation/file-provider retention/hold/reference policy and is never
caused by Mail deletion/revocation. Mail attachment deletion/hold/export follows Email lifecycle and
does not remove the durable copy. Target portal publication is a separate Documentation/portal action.

## Provider Interface And Nextcloud

Add provider-neutral `FileProviderWriter`/`FileProviderInspector` DTO contracts with safe capability,
destination/object/version/upload identities and bounded stream input. The Nextcloud implementation
extends the existing module; Email never calls `NextcloudReadClient` directly.

Harden Nextcloud request/runtime boundaries: fixed active HTTPS connection, scoped service/user
credential, target-root containment, redirects/SSRF/TLS/deadline/response caps, deterministic close,
safe errors and no secret/request telemetry. Use `Overwrite: F`, checksum/total-length and provider
file ID/ETag. Arbitrary sharing/public links, remote URL fetch, server-side copy from untrusted path and
folder-recursive actions are unavailable.

## Permissions And API

Add narrow permissions for Documentation file create/link/version/delete and provider-folder write;
do not infer them from Email View, Documentation View or Nextcloud Admin. The handoff needs Mail View/
attachment access plus file create/link and Work Context authorization. Break-glass/risk download/
Ticket relationship/rule/AI/API broad abilities do not copy.

Email API creates/previews/reads handoff status only for exact source; Documentation API owns files/
links/versions. Service tokens need explicit source account and target Work Context/provider abilities.
All binding is opaque/non-enumerating and reauthorized at request/job/stream.

## Bounds

- Default one/hard 20 attachments per preview; per-file/product/account/provider byte caps and
  cap-plus-one denial.
- One file per job; bounded memory, request/overall time, chunks (max 10,000), provider seconds,
  attempts and unresolved reconciliation.
- No recursive folder selection, wildcard target, select-all mailbox or synchronous browser upload.
- Metrics show safe counts/bytes/status/age/provider health without mailbox/client/file names.

## Tests

- Exact Mail/attachment/current-clean hash plus Documentation/WorkContext/provider permission cross-
  product; personal/shared/delegation/revoke/break-glass, Ticket/portal and non-enumerating UI/API.
- Filename/path/Unicode/traversal/symlink/URL/collision, mapped root, overwrite denial, explicit suffix/
  version and target drift.
- Small PUT and chunk-v2 exact chunk/order/total/destination/assembly, quota/lock/timeout/redirect/TLS/
  SSRF/response caps, file ID/ETag/size/checksum verification and guaranteed close.
- Pre-write retry, response-loss unresolved/no duplicate, exact reconciliation, conflicting object,
  cancellation/cleanup/losing worker/redelivery/idempotency and partial multi-item report.
- Source revoke/delete/change after successful copy, independent target access/retention/hold/version/
  unlink/delete, provenance hiding and no provider ACL/public share/portal publication.
- Non-clean/stale/malicious/missing bytes absolute block, scan-policy change during upload and no
  rule/AI/Ticket/provider-Mail/personal-unread side effects.
- Migration/down guards, queue/scheduler/health, API/OpenAPI, Bootstrap/mobile/accessibility and
  affected Email/Documentation/Nextcloud/Integration/Ticket/Portal tests.

## Documentation And Operations

Update Email/Documentation/Nextcloud/Integration/Ticket/Portal Knowledge, WebDAV credential/folder/
upload/reconciliation/lifecycle runbooks, API/OpenAPI, TODO, completion index and
`docs/human-review.md`. Configure an expendable Dev Nextcloud target with app credential, managed-write
mode and exact folder mapping; deploy additive schema/permissions with `umask 0002`, clear caches,
rebuild group-writable views and restart workers. No migration/provider upload/link is automatic.

`HR-2026-08-16-030` remains Pending until a named reviewer verifies real small/chunked uploads,
response-loss reconciliation, permissions, clean gate, independent lifecycle and sanitized provider
runtime. Provider write activation remains explicit.

## Done Criteria

- [ ] A clean exact Mail attachment becomes an independent Documentation-owned file only through a
  previewed, authorized, verified provider handoff.
- [ ] Small/chunked Nextcloud writes are bounded, no-overwrite, checksum/file-ID verified and no-
  duplicate under uncertainty.
- [ ] Mail, Documentation link/file and provider bytes have explicit independent access/lifecycle;
  none grants or deletes another implicitly.
- [ ] Tests, migrations, permissions, UI/API, workers/scheduler, docs/runbooks and
  `HR-2026-08-16-030` are complete while named human review/provider activation remain Pending.
