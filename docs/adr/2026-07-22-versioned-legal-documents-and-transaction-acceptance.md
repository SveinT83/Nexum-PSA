# ADR: Versioned Legal Documents And Transaction Acceptance

Status: Accepted
Date: 2026-07-22
Decision Makers: Svein Tore / Codex

## Context

CloudFactory catalogue offers may include provider legal documents, links, identifiers, or versions.
Nexum also needs its own approved terms on the same Service. Provider content can change after a
contract has been accepted, and portal licence changes can create a new commercial commitment.
Overwriting one current text field would destroy the evidence of what the customer accepted.

CloudFactory does not guarantee that every catalogue product supplies full legal content. Nexum must
therefore distinguish a verified provider document from commercial term metadata and from a document
that was not supplied.

## Decision

A term is a logical document with immutable versions. Provider documents are synchronized as
read-only records owned by their source Integration. A changed title, issuer, version, content,
effective date, or source URL creates a new checksum-addressed version; it never edits an older
version. Missing documents are marked as not returned and retained.

CloudFactory terms attach to the source offer and its one-to-one Nexum Service. Additional Nexum
terms attach from the approved legal library. Service screens can select or remove only Nexum-owned
terms and do not provide inline document editing.

Contracts retain their existing combined text snapshots and additionally capture the exact term
version rows for every Service line when sent. Customer-portal contract acceptance and every portal
licence issue, quantity change, or renewal-policy change create append-only acceptance evidence with
the account, membership, contact, user, contract line, Service, offer, subscription, current
document IDs and checksums, quantity, price, commitment, timestamp, IP address, and user agent.

Only a client-level Customer admin can order licences. The portal offers only exact CloudFactory
variants already present on a won, active contract that permits the action. Automatic renewal already
authorized by the contract does not prompt again unless a portal user changes the renewal setting.

## Rationale

Immutable versions preserve historical acceptance while letting current Services receive provider
updates. Separating provider-managed and Nexum-managed documents prevents accidental edits and keeps
Nexum additions possible. Transaction evidence is stricter and easier to audit than assuming that an
old contract acceptance silently covers every later quantity or renewal change.

## Consequences

- Catalogue synchronization also checks known legal-document fields monthly.
- Enabling a staged offer queues a current catalogue check and immediately links any already stored
  provider documents.
- Provider payloads without supported legal fields show Not supplied by provider; Nexum does not
  invent legal content.
- Existing contract text snapshots remain valid and readable.
- Portal ordering requires explicit confirmation for every write.
- A human review and live provider-payload check remain required because CloudFactory has no sandbox
  and does not guarantee legal-document fields for every product family.

## Alternatives Considered

- Overwrite terms in place: rejected because prior acceptance evidence would change retroactively.
- Copy provider text into an editable Service field: rejected because provenance and update ownership
  would be lost.
- Require full contract reacceptance for every quantity change: rejected as unnecessarily broad;
  the transaction confirmation captures current changed documents and commercial context.
- Assume every provider product has legal content: rejected because the API contract does not
  guarantee it.
