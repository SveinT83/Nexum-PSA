# Feature Slice: Signal API Ingest

Status: Done
Date: 2026-06-09
Parent: `docs/rfc/2026-06-09-signal-domain-active-automation.md`
Owner: Codex

## Goal

Allow external systems and integrations to record normalized Signal events through the existing
Sanctum API model.

## User-Visible Behavior

API tokens with `signals.create` can post events to `/api/v1/signals`. Stored signals appear in the
Signal feed, execute matching rules, and can trigger existing Signal actions such as webhooks,
Sales follow-up, Ticket follow-up, tagging, or marketing suppression.

## Scope

- Protected `POST /api/v1/signals` endpoint.
- Signal API resource response.
- API ability catalog entry.
- Feature tests for authorized and unauthorized API access.

## Out Of Scope

- Public unauthenticated webhooks.
- Provider-specific parsers.
- AI classification.
- Marketing-owned engagement lists.

## Data Touched

Writes `signals` and normal Signal rule execution side effects.

## Permissions

Adds API token ability `signals.create`.

## Tests

Signal feature tests cover API route ownership, successful ingest with rule execution, and forbidden
access without `signals.create`.

## Documentation

Updated Signal README and Knowledge documentation.

## Done Criteria

- External systems can record a signal using an API token.
- Rules execute for ingested signals.
- API ability is available in the Integration API key UI.
- Targeted tests and cache checks pass.
