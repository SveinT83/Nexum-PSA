# Feature Slice: AI-Assisted Supplier Order Resolution

Status: Implemented - Awaiting Human Review
Date: 2026-08-04
Parent: `docs/rfc/2026-08-04-storage-supplier-email-purchase-order-automation.md`
Owner: Svein / Codex

## Goal

Add a governed, non-writing Purchase Order Import Agent that resolves uncertain extraction and Item
suggestions through strict evidence, then allows settings-controlled AI-verified auto-registration
only after Storage hard gates pass.

## User-Visible Behavior

When deterministic extraction is incomplete, AI-enabled profiles can resolve the uncertain document
without routine human approval. Import detail shows which fields used AI, evidence, Nexum-computed
confidence, provider/workload attempt status, and why the policy did or did not auto-register.

AI-disabled installations and provider outages keep deterministic-safe processing and send only
AI-required imports to retry/exception.

## Scope

- Create a dedicated Storage-domain Purchase Order Import Agent with
  `can_execute_actions=false`.
- Bind it to one explicit Integration workload, provider/model governance path, processing mode,
  data profile, purpose, limits, and telemetry feature key.
- Call only the Integration-owned privacy and provider execution boundary.
- Minimize source content and remove remote links, tracking tokens, unnecessary headers/payment
  facts, and unrelated personal data before egress.
- Treat message/profile content as untrusted and provide no tools or network access.
- Require strict versioned JSON schema with explicit unknowns, per-field/line evidence, and bounded
  output.
- Verify evidence against the safe source, recompute totals, and reject unsupported/hallucinated
  facts.
- Keep separate source-trust, document, extraction, Item-resolution, validation, and AI-result
  dimensions; use the weakest critical dimension.
- Add action-specific thresholds, optional high-risk consensus, token/cost/time limits, and
  `auto_verified_ai` mode.
- Allow AI to suggest Item candidates but never name-only merge or direct-create them.
- Retry transient provider failures and fail closed on governance/privacy/schema failure.
- Record execution UUID/provider/model and sanitized operational facts without raw email, prompt,
  response, credentials, headers, or raw provider errors.

## Out Of Scope

- AI profile activation/repair lifecycle; that is the next slice.
- Generic Inbox AI analysis.
- AI tools, direct domain writes, supplier-order submission, and receipt/stock mutation.
- Sending unsupported attachments to a provider.

## Data Touched

- Integration AI agent/workload/governance bindings and usage execution metadata.
- Storage import attempts, evidence, confidence dimensions, AI decision facts, and policy revisions.
- No raw prompt/response content in Signal or mandatory telemetry.
- Existing PO/Item writes only through already approved Storage actions/policy.

## Permissions

- `storage.purchase_import_execute` for explicit AI retry where allowed.
- Storage policy controls automatic AI usage; the AI agent has no write permissions/tools.
- Integration governance/provider permissions remain Admin-only and separate.
- Effective user/service actor authorization still governs any later domain action.

## Tests

- AI disabled, provider absent, workload unapproved/expired, model denied, privacy-gateway failure,
  direct-external denied, and local mode.
- Strict valid/invalid JSON, unknowns, evidence present/missing, hallucinated values, excessive
  output, timeout, retry, and consensus disagreement.
- Prompt injection in subject, footer, product name, HTML, and profile fixture has no tool or policy
  effect.
- Minimized outbound payload and negative assertions for secrets, unrestricted headers, URLs, and
  unrelated data.
- Model-reported 100% confidence cannot bypass source, arithmetic, identity, cap, or actor gates.
- Deterministic-safe imports continue during provider outage; AI-required imports retry then become
  clear exceptions.
- AI-verified outcome creates at most one PO through the deterministic action path and never stock.
- Usage/access audit contains only approved sanitized metadata.

## Documentation

- Integration Knowledge for the PO workload, processing modes, governance, privacy, usage/cost, and
  outage behavior.
- Storage Knowledge for AI fallback, evidence, confidence, settings, and exceptions.
- Security/operations runbook and TODO/human-review updates.

## Done Criteria

- [x] All AI calls use the approved Integration boundary and explicit internal workload.
- [x] The agent is non-writing and tool-free.
- [x] AI output is strict, evidence-backed, minimized, and deterministically verified.
- [x] `auto_verified_ai` remains subordinate to every Storage hard gate.
- [x] AI-disabled and provider-outage behavior is complete and honest.
- [x] Privacy, telemetry, prompt-injection, retry, policy, and no-stock coverage is implemented on Dev.
- [ ] Complete a controlled provider smoke under effective Integration governance and the named human reviews before AI activation.
