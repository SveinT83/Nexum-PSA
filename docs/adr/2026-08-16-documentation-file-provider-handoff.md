# ADR: Documentation-Owned File Records And Provider Handoff

Status: Accepted
Date: 2026-08-16
Decision owners: Documentation / Nextcloud / Email / Integration
Related RFC: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Feature Slice: `docs/feature-slices/2026-08-16-email-mail-documentation-file-provider-handoff.md`

## Context

An Email attachment is mailbox-owned evidence with access and lifecycle tied to its exact source
placement. Technicians also need to preserve a reviewed file as durable client/site/internal
documentation or in an external file provider. A copied file must not retain Mail as its authority,
and a mere provider URL/path is not a durable, authorized record. Conversely, linking a Ticket or
Documentation record must not grant access back into the mailbox.

The existing Documentation model has no first-class file record. Nextcloud can list WebDAV files but
has no bounded, idempotent managed upload action. Its connection is the selected first provider, so
write semantics and lifecycle require an explicit decision rather than direct calls from Email.

## Decision

Documentation owns a durable `DocumentationFile` record and links it to Documentation/WorkContext/
client/site records through guarded actions. File-provider modules own provider connection,
destination authorization, upload/verify/version/delete lifecycle and provider credentials. Email
owns the original attachment, exact source access and an immutable handoff request/evidence link.

The first provider implementation is Nextcloud WebDAV. Introduce a provider-neutral file write/
inspect contract, then implement Nextcloud PUT for bounded small files and chunked-upload v2 for larger
files. Uploads use a unique temporary/upload identity, `Overwrite: F`, total length and current clean
SHA-256 evidence; final provider file ID, ETag, size and checksum are verified before success. A
response-loss state is reconciled by exact destination/provider identity and checksum and is never
blindly uploaded again.

Copying creates a new independent Documentation-owned file record only after provider confirmation.
The record retains provenance to the source attachment/message/conversation and clean scanner
evidence, but does not make the mailbox readable. Subsequent Mail revocation does not delete or hide
the intentionally handed-off copy from users authorized to its Documentation Work Context.

`Link` means attaching an already durable `DocumentationFile` to another authorized Documentation/
WorkContext record. It does not create a public URL, link an arbitrary remote path, or use the live
Email attachment as permanent storage. Unlink removes the relationship, not provider bytes; provider
delete is a separate Documentation/file-provider lifecycle action.

## Authorization And Security

Every copy preview/action intersects current exact Mail View/attachment access, current clean content
evidence, target Documentation create/update permission, target Work Context/client/site authority,
and provider connection/folder write permission. A Ticket link alone, account configuration, break-
glass, raw/risk-download, AI/rule/system actor or file-provider admin alone is insufficient.

Filename is sanitized separately from provider path. Destination uses provider-issued folder identity
or an allowlisted mapping, never user-controlled URL/host/absolute traversal. Symlinks/external shares
and public-link creation are prohibited. Provider credentials/endpoints are resolved only inside the
provider runtime and never appear in Email jobs/audit/UI.

Known malicious/non-clean/stale/mismatched content cannot be copied or linked as an ordinary file.
Order-21 exact current clean evidence is rechecked while streaming and after upload. File-provider
malware policy may add a stricter second scan, never weaken Email's result.

## Nextcloud Semantics

Use the fixed reviewed Nextcloud connection and WebDAV user root. Resolve destination within a mapped
client/site/internal documents folder. Create missing folders only when the provider connection mode,
mapping and explicit action permit; never infer them from untrusted filename/client label.

Small uploads use exact `PUT` with overwrite disabled. Large uploads use chunked v2 with bounded 5 MiB
minimum chunks (except final), maximum 10,000 chunks, unique upload directory, `Destination` and
`OC-Total-Length`; final MOVE assembles the object. Cleanup may delete only the exact unfinished
token-owned upload directory. The returned `OC-FileId`/ETag plus PROPFIND/checksum/size are durable
verification evidence. Provider versions/conflicts remain visible; overwrite is a separate explicit
new-version action.

## Consequences

- Documentation receives a real file model rather than embedding provider paths in JSON.
- Email attachment and durable copy have independent access/lifecycle after explicit handoff.
- Nextcloud becomes the first provider behind a replaceable interface; other providers need their own
  driver/ADR without changing Email.
- Upload uncertainty requires reconciliation and may temporarily show pending/unresolved.
- Deleting/unlinking Mail, Documentation and provider objects remains three distinct authorities.

## References

- [Nextcloud WebDAV file operations](https://docs.nextcloud.com/server/stable/developer_manual/client_apis/WebDAV/basic.html)
- [Nextcloud chunked upload v2](https://docs.nextcloud.com/server/latest/developer_manual/client_apis/WebDAV/chunking.html)

## Verification

Provider fakes plus an expendable Nextcloud folder must prove exact small/chunked upload, no-overwrite,
checksum/file-ID/ETag verification, response loss/reconciliation, cancellation cleanup, Work Context
authorization and independent Mail/Documentation/provider lifecycle before enabling the handoff.
