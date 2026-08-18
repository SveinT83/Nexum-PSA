# Feature Slice: Email Mail AI Reply Drafting

Status: Done
Date: 2026-08-12
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Add safe Mail AI writing assistance for Reply and Reply All composers without automatic sending or
other write actions.

## User-Visible Behavior

When Mail AI has a selected Email agent or global fallback agent for the signed-in user and
Integration governance allows that agent/model runtime, a send-authorized Reply or Reply All
composer shows compact AI controls. Users can draft a reply, improve existing composer text,
shorten it, make the tone warmer, or rewrite it in Norwegian. An optional guidance field lets the
technician steer the draft.

When AI returns a sendable draft, the result replaces only the composer body. If AI determines that
no reply is recommended, such as for an automated RMM alert or status notification, the composer body
is left unchanged and the reason is shown as an advisory status. The user must review and press Send
manually through the existing composer flow.

## Scope

- Add `AssistEmailComposerWithAi` for governed structured composer assistance.
- Add Reply/Reply All composer AI controls in `/tech/mail`.
- Require effective mailbox View and Send access before AI can use selected message context.
- Let admins choose the Default Email agent directly from Email settings. Clearing that field uses
  the global default fallback agent for user-triggered Mail AI.
- Hide and clear the legacy `mail_ai_workload_profile_id` setting so structured workload override no
  longer controls Mail AI runtime selection.
- Check Integration runtime readiness before showing or executing Mail AI composer controls; missing
  model governance returns `model_governance_missing` and sends no provider request.
- Surface the Mail AI readiness reason in Email settings and link admins to the standard AI Settings
  activation path.
- Send only authorized message text, bounded conversation text, composer plain text, subject, intent,
  and optional technician guidance to the selected/default Email agent.
- Require composer AI responses to distinguish a sendable body from a no-reply recommendation through
  `reply_recommended`/`user_notice`, with a defensive guard for old responses that put no-reply
  advice in `body`.
- Convert AI plain-text output to escaped composer HTML before displaying it.
- Preserve existing To, Cc, Subject, attachments, idempotency key, mailbox placement, folders,
  Ticket links, categories, tags, and provider state.
- Expand `common_settings.value` to `TEXT` so Email settings can store default MIME policy and other
  bounded long settings while saving the Mail AI agent configuration.

## Out Of Scope

- Automatic sending, scheduled sending, or one-click AI send.
- AI attachment analysis, raw source/header analysis, or original attachment forwarding.
- AI-generated recipient changes, subject changes, folder moves, Ticket/Task creation, rule creation,
  category/tag application, or provider operations.
- New compose AI, full template/content-block editor, and per-user AI preferences.

## Data Touched

- `common_settings.value` column type changes from `VARCHAR(255)` to `TEXT`.
- No outbound Email log, provider operation, Taxonomy, Ticket, Task, rule, or mailbox state is
  created by composer AI.

## Permissions And Governance

Composer AI requires the user's active Email/default agent, Integration policy readiness for that
agent/model, plus effective mailbox View and Send access for the selected placement. Execution goes
through `AiAgentResolver` and `AiChatResponder` with an explicit non-writing JSON prompt. A broad
action-capable default agent may be used for these manual Mail AI drafting/summarizing actions
because this path does not call tools, write APIs, send mail, update Tickets, create Tasks, move
messages, or apply rules/tags.

The request states `attachments_included=false`, `raw_source_included=false`,
`composer_markup_included=false`, `output_is_non_mutating=true`,
`recipients_are_not_changed=true`, and `send_is_not_allowed=true`.

## Tests

- Livewire feature test for drafting a reply without sending or changing recipients.
- Livewire regression test that no-reply advice for automated alert mail leaves the composer body
  unchanged and shows an advisory status.
- Livewire feature test for rewriting existing reply text with technician guidance.
- Email settings regression test for saving Mail AI settings together with the full default MIME
  allowlist.
- Email settings regression test for hiding structured workload override and clearing the legacy
  setting on save.
- Email settings regression test for selecting and clearing the Default Email agent.
- Livewire regression test that model-governance denial hides composer AI controls and avoids a
  provider request.
- Integration regression tests for the standard AI activation button and required admin
  confirmation.
- Email regression test that standard AI activation makes an external default Email agent available
  without using the legacy structured workload override.
- Livewire tests for summary and Reply drafting through an action-capable default Email agent
  without write side effects.

## Documentation

- Email README and Knowledge overview updated.
- TODO active Mail workstream updated.
- Human review tracked in `HR-2026-08-12-017`.

## Done Criteria

- AI composer controls appear only for ready Mail AI, selected authorized mail, and Reply/Reply All
  composers with Send access.
- AI input excludes raw source, HTML markup, attachment contents, and attachment filenames.
- Sendable AI output changes only the composer body and cannot send or mutate records.
- No-reply recommendations do not replace the composer body with technician advice.
- Email settings can save the default attachment MIME list and selected Default Email agent.
- Structured workloads are not selected for Mail AI; legacy workload settings are cleared and
  ignored when a default Email agent is available.
- Default Email agents can power user-triggered non-writing summary and Reply assist.
- Focused and affected Email tests pass on Dev.
