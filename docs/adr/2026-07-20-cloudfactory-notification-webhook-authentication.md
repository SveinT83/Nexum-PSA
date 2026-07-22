# ADR: CloudFactory Notification Webhook Authentication

Status: Accepted
Date: 2026-07-20
Decision Makers: Svein Tore / Codex
Related: `docs/rfc/2026-07-16-cloudfactory-partner-integration.md`

## Context

CloudFactory has confirmed in writing that notification webhook requests are not cryptographically
signed. The configured secret is transmitted verbatim in the `X-API-KEY` header over HTTPS.
Notification payloads contain `EventKey`, `CreatedAt`, `SentAt`, and `PartnerGuid`, but no unique
event identifier. Failed deliveries are retried with an identical payload for approximately 24 hours.

Nexum needs low-latency notification handling without treating a retry as a second business event or
trusting webhook data as authoritative provider state.

## Decision

Nexum generates a random 64-character shared key, stores it encrypted with the Integration secrets,
and registers it as the `X-API-KEY` header through CloudFactory's Notification API. The public
endpoint accepts HTTPS JSON requests only for an active CloudFactory integration with webhooks
enabled, applies a body-size limit and rate limit, and compares the header in constant time.

The endpoint also verifies that `PartnerGuid` matches the connected partner. It creates a
deterministic SHA-256 fingerprint from normalized `EventKey + CreatedAt + PartnerGuid`. The first
delivery creates one receipt and queues reconciliation; identical retries return an accepted response
without queuing duplicate work.

`SentAt` and `CreatedAt` must be valid timestamps, but Nexum does not enforce a short freshness
window because CloudFactory legitimately retries the identical payload for approximately 24 hours.
Only the minimum event metadata is retained. The webhook never directly changes Clients, Services,
contracts, licences, or billing records; it selects and queues the normal authenticated reconciliation
path. Scheduled polling remains enabled as the recovery and completeness mechanism.

## Rationale

A shared header key over HTTPS is the provider's supported authenticity contract. Constant-time
comparison, partner binding, minimized receipt storage, deterministic deduplication, and normal
reconciliation reduce the risks created by the absence of a signature and unique event ID.

## Consequences

- The CloudFactory Portal account requires Partner Admin to discover and register notifications.
- The shared key must never be displayed after generation or written to logs or audit metadata.
- Re-registering refreshes the provider headers without changing the key.
- Disabling webhooks removes known provider registrations before deleting the local key.
- Revocation removes webhook registrations before revoking the Portal tokens.
- Polling is still mandatory and webhook payloads are notification hints, not domain truth.
- Live provider registration and one real delivery remain part of `HR-2026-07-20-001`.

## Alternatives Considered

- HMAC verification: rejected because CloudFactory does not sign the payload.
- A short `SentAt` replay window: rejected because it would reject documented provider retries.
- Processing the webhook payload as domain truth: rejected because its schema varies by event and
  the authenticated provider APIs remain authoritative.
- Webhook-only synchronization: rejected because deliveries can fail and have no globally unique ID.
