# Feature Slice: Integration Standard AI Activation

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-14-organization-controlled-ai-data-access.md`
Owner: Svein / Codex

## Goal

Let an administrator activate the normal governed AI path from AI Settings without manually filling
every provider and model governance form.

## User-Visible Behavior

AI Settings shows a compact **AI Activation** card with installation status, provider, model, an
organization approval checkbox, and an **Activate AI** button. After confirmation, Nexum records the
installation policy revision, provider governance profile, and model governance policy required for
ordinary user-triggered assistants such as Mail AI. Advanced Privacy & Coordinator settings remain
available for narrower or more detailed governance.

## Scope

- Add `ActivateStandardAiRuntime` to atomically update the installation AI policy and create/update
  provider/model governance records.
- For external providers, activate `direct_external` with `full_context` because Mail AI sends
  authorized message text.
- Require an explicit admin confirmation before activation.
- Keep advanced governance forms and policy enforcement unchanged.
- Add AI Settings status and readiness messaging.
- Let Email settings link to AI Settings when Mail AI has a selected/default agent but the
  provider/model policy is not ready.

## Out Of Scope

- Legal certification by Nexum.
- Automatic provider contract verification.
- Automatic AI replies, AI write tools, coordinator tokens, or new data sources.
- Removing or weakening the advanced Privacy & Coordinator governance model.

## Data Touched

- `ai_data_egress_policies` and `ai_data_egress_policy_revisions`.
- `ai_provider_governance_profiles`.
- `ai_model_governance_policies`.
- No Mail content, provider mailbox state, Tickets, Tasks, Taxonomy, or API token data is changed.

## Permissions And Governance

The activation card is on the existing Admin AI Settings page. The administrator must confirm that
the organization has reviewed and approves the selected provider/model for Nexum AI features. The
recorded governance is an enforcement input and audit trail; it is not a Nexum legal certification.
Agent policies, workload policies, mailbox authorization, and domain guards may still narrow or deny
individual AI requests.

## Tests

- Integration Livewire regression that starts from fail-closed defaults, activates a selected
  external provider/model, and verifies installation, provider, model, and revision records.
- Integration Livewire regression that activation is rejected without the admin confirmation.
- Email regression that the same activation makes an external default Email agent available for Mail
  AI without a structured workload override.

## Documentation

- Integration AI governance Knowledge updated.
- Email Knowledge and README updated.
- TODO active Mail/AI workstream updated.
- Human review tracked in `HR-2026-08-12-018`.

## Done Criteria

- A normal admin path can make Mail AI ready without editing provider/model governance by hand.
- The central data-egress policy gate still enforces the resulting records.
- Missing confirmation does not activate AI or create model governance.
- Advanced governance remains available for stricter configurations.
- Focused Integration and Email regressions pass on Dev.
