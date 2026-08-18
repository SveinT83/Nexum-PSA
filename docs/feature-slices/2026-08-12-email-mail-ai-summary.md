# Feature Slice: Email Mail AI Summary

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Add a first safe Mail AI capability in `/tech/mail`: summarize the selected authorized message or
bounded conversation, extract likely questions and action items, and suggest non-mutating labels.

## User-Visible Behavior

When the signed-in user has a selected Email agent or global fallback agent and Integration
governance allows that agent/model runtime, the Mail command bar shows an AI summary icon for an
authorized selected message. Clicking it opens a read-only panel with summary, key points, questions,
action items, suggestions, urgency, reply-needed signal, and provenance notes.

If no Email/default agent is available to the user, the icon is hidden and direct Livewire calls fail
with a visible warning. The feature never sends mail, drafts replies, moves messages, creates rules,
creates Tickets, changes categories/tags, or calls external tools.

## Scope

- Add `SummarizeEmailWithAi` to build a non-writing JSON prompt for the selected/default Email
  agent through `AiChatResponder`.
- Add `MailAiAgentRuntime` readiness checks so Mail AI controls are hidden when Integration policy
  denies the selected/default agent, including missing model governance.
- Add Mail workspace AI summary action and read-only result panel.
- Add Email Sync & Cache Settings selection for the Default Email agent, with blank selection using
  the global fallback agent.
- Show a compact Email settings readiness hint with a link to AI Settings when the selected/default
  agent is blocked by Integration policy.
- Limit AI input to authorized message text and bounded mailbox metadata.
- Exclude raw source, HTML, attachment contents, and attachment filenames from the request.

## Out Of Scope

- Reply drafting, send suggestions, automatic replies, and any outbound mail.
- Applying labels/tags/categories, moving mail, creating Tickets/Tasks, or adding rules.
- Attachment analysis, raw-header analysis, provider-specific AI tools, and AI tool execution.
- Bulk AI over mailbox lists or cross-mailbox search.

## Data Touched

- Email-agent defaults on `ai_agents.default_domains`.
- Legacy `common_settings` with `type=emailhub` and `name=mail_ai_workload_profile_id` is cleared on
  the next Email settings save and is not used for Mail AI runtime selection.
- No migration and no Mail state mutation.

## Permissions And Governance

Mail AI requires the actor to have effective mailbox View access for the selected placement and an
active available Email/default agent whose provider, model, installation policy, and optional agent
governance pass `AiOutboundPolicyGuard`. Runtime execution goes through `AiAgentResolver` and
`AiChatResponder` with an explicit non-writing JSON prompt. Action-capable agents are allowed on
this manual path because the Mail AI buttons do not call tools or write APIs.

The request states `attachments_included=false`, `raw_source_included=false`, and
`output_is_non_mutating=true`. The response is treated as advisory text only.

## Tests

- Livewire feature test for generating a governed AI summary from an authorized selected message.
- Livewire feature test that hides and denies the action without an Email/default agent.
- Livewire feature test that hides Mail AI controls and returns `model_governance_missing` when the
  selected Email agent's model policy is missing.
- Regression test that Integration standard AI activation makes an external default Email agent
  available without a Mail-specific structured workload.
- Admin feature test for selecting/clearing the Default Email agent and clearing the legacy workload
  setting.
- Full Email module feature test and Email inbound automation test pass on Dev.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `HR-2026-08-12-016`.

## Done Criteria

- AI input excludes raw source, HTML, attachment contents, and attachment filenames.
- Unauthorized mailbox access cannot invoke Mail AI.
- The UI is hidden until an Email/default agent is available.
- AI output is read-only and cannot mutate Email, Ticket, Task, Taxonomy, rules, folders, or sends.
- Focused and affected Email tests pass on Dev.
