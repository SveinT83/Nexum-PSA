# ADR: Asset Commands And Provider Scope

Status: Accepted within Svein Tore's explicitly authorized full browser/API scope.
Date: 2026-10-26
Decision Makers: Svein Tore (product/release); Codex (implementation).

## Context
Manual Assets, settings and Livewire provider operations need one supported API
without changing polymorphic identities or accidentally activating automation.

## Decision
Keep Asset routes in Asset/routes.php, use the existing permission catalog and
current Core User rights, and share domain validation/query/mapping with web.
Store actor-and-token-bound keyed receipts with encrypted exact results. Take one
Asset mutex before local mutations and revisions. Commit provider claims before
network reads; claim replay never runs external work again. Interrupted claims
remain running/unknown and require reconciliation. Provider execution is default-off
and requires an explicit scope preview and confirmation. The preview fence covers
ownership and provider mappings. Hold the same mutex across execution so manual
Asset edits cannot invalidate ownership between the fence and application.

## Rationale
This preserves existing API enrichment/update compatibility and browser defaults,
closes duplicate command risks and makes observation, partial provider failure and
unknown completion distinguishable. Tactical transport now exposes a boolean outcome
without changing its existing array contract; an empty failed response is not success.
N-able import keeps existing placeholder-site behavior in a shared domain action.

## Consequences
No Asset namespace move, provider activation, worker or scheduler registration.
One additive receipt/mutex migration must precede writes after deployment. Keep
APP_KEY and command rows on rollback. A domain-wide lock can serialize long provider
imports; production throughput and a bounded asynchronous follow-up remain unmeasured.
Remote APIs are read for import/checks; local alert observation can invoke existing
Integration-owned rules and notifications. No new escalation/task routing is added.

## Alternatives Considered
Duplicating Livewire logic would drift from web; generic integration dispatch could
expand scope; queue acceptance would not prove completion. None satisfy this contract.

## Follow-Up
Named human checklist HR-2026-10-26-ASSETS-API remains pending. Explicit Main request
allows scoped promotion; production rollout/provider activation remain separate.
