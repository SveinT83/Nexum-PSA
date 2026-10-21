# RFC: Mark All Messages In The Selected Mail Conversation Read

Status: Approved
Date: 2026-10-21
Owner: Svein Tore / Codex
Level: 3 (user-visible read-state workflow and shared browser/API runtime contract)
Parent: 2026-07-04-mail-module-full-email-client.md
Related implementation: ../feature-slices/2026-08-16-email-mail-conversation-acknowledgement-multi-account-actions.md
Related review: HR-2026-08-16-012 (In Review; advanced runtime disabled)
Implementation status: Deployed to production through Main PR #305; human review In Review.
Verification: ../plans/2026-10-21-mail-conversation-read-verification.md.
Human review: HR-2026-10-21-MAIL-CONVERSATION-READ In Review.

## Context

The current reader's Mark as read command changes the selected message. The user wants an explicit
way to mark the entire open conversation read. The supplied screenshot shows six messages in the
conversation but only one matching the current list view. The bulk action must therefore distinguish
the whole conversation from the filtered list and from the selected individual message.

The existing preview/apply run-item ledger already snapshots an active-account conversation,
reauthorizes each item, separates personal/provider outcomes, excludes later arrivals and bounds
continuation. Its global acknowledgement flag is currently false on authoritative Dev; both ledger
tables exist. Current Livewire methods exist, but the reader renders no conversation-wide command.
The advanced acknowledgement review and runtime gate must not be silently approved or enabled.

## Goals

- Provide a compact, discoverable command beside the existing Mark as read/unread button.
- Offer an explicit counted action for the entire conversation in the active mailbox.
- Preserve selected-message behavior, manual acknowledgement and personal/shared mailbox boundaries.
- Give truthful completion, partial/provider and queued outcomes through shared browser/API actions.
- Keep later arrivals unread and preserve other users' personal state.

## Non-Goals

- Automatically mark mail read when opening or hovering over a conversation.
- Change ordinary primary-button behavior according to an implicit selection mode.
- Select mail from other accounts or apply mailbox-wide/read-all search operations.
- Enable advanced multi-account operations, unrelated runtime flags or production behavior.
- Add new read-state tables, silently merge legacy conversations, or bypass existing authorization.

## Current Behavior

MailWorkspace::markSelectedRead / markSelectedUnread call SetEmailSelectedReadState for one exact
selected placement. Shared/delegated mail updates only the acting user's Nexum state. For the owner
of a personal account, the existing command additionally requests provider Seen/Unseen and reports
that outcome separately.

PreviewEmailConversationAcknowledgement and ApplyEmailConversationAcknowledgement implement
frozen membership, per-item reauthorization and durable results. Apply handles at most 25 items per
invocation; only remaining work requires queue continuation. The global advanced gate is false.
Opening a conversation is deliberately non-mutating.

## Proposed Change

Use a Bootstrap split button in the reader command bar:

- Main button: existing Mark as read / Mark as unread for the selected individual email.
- Adjacent arrow: opens an explicit conversation action menu, usable by mouse click, touch and
  keyboard. Hover may show a scope tooltip but is never the only way to access the action.
- Menu action: Mark all 6 messages in conversation as read (with the actual frozen count).
- Clearly label the active mailbox. The scope includes authorized Inbox/Sent/other-folder context
  belonging to that same conversation, including messages outside the current list/search filter.
- Opening the arrow prepares a bounded, non-acknowledging snapshot. The counted menu action confirms
  exactly that snapshot; selecting it applies the operation. Loading/failure states cannot show an
  actionable stale count. Closing/reopening or changing the selected conversation refreshes scope.
- Keep Mark all as read available when the selected individual message is already read but another
  message in the conversation is unread. Report already-read messages without duplicate effects.
- Opening the conversation or selecting its parent row never marks any messages read. No automatic
  all-selected state is inferred from the absence of a child-row click.

All read changes affect only the acting user's state. Personal-owner provider Seen follows the
ordinary selected-message contract, with separate truthful provider status; shared/delegated mail
does not request provider Seen implicitly. Do not expose technical provider-setting controls in this
ordinary menu.

Messages arriving after snapshot preparation are outside the command and stay unread. Recheck actor,
account, current access epoch, exact message/placement/conversation, provider identity and scope at
apply time. No hidden-account counts, cross-account expansion, silent truncation or stale replacement.
Deduplicate personal effects when the same canonical message has several placements.

Use the existing durable run/item and remote-operation actions. Keep the broader multi-account,
provider-option and unrelated advanced paths behind their current gate. The ordinary active-account
read command needs an explicitly narrow server-enforced policy shared by browser and official API;
do not turn on the broad global acknowledgement flag merely to make this button appear.

For legacy header-grouped conversations, use the current authorized bounded reader membership and
an exact frozen selection; do not guess a new conversation identity or mark only the visible page
while calling it all. If the current scope is incomplete or exceeds the verified bound, refuse
the all operation with an honest explanation before any read-state change.

## Impact Analysis

Email owns the Livewire/Blade command, snapshot/apply actions, runtime-policy boundary, typed API
context/contracts, OpenAPI/parity manifest and tests. No new module or cross-domain ownership.
Use module-owned routes if an API addition is necessary; prefer the current official conversation
action path where it can expose the exact ordinary command without broadening advanced operations.
Keep current View/personal-state authorization; provider mirroring additionally follows current
owner/Organize checks. Break-glass and revoked access must not become ordinary read-state grants.

Existing shared dirty Mail changes must be preserved. This work continues the existing
conversation-acknowledgement area after the reviewed Trash fix was promoted, rather than starting
another unrelated feature. Update the same backlog references and the related review with precise
scope; never mark the broader review Reviewed from approval of this RFC alone.

## Data And Migration Plan

Reuse the installed run/item ledgers and exact immutable snapshot evidence. No migration, backfill,
historical acknowledgement or runtime activation is planned. Verify the current schema and scope
discriminator before implementation. Any required schema/architecture change must be documented in
an ADR and reviewed within this RFC before execution. Do not widen a runtime guard for all existing
conversation action types as an incidental shortcut.

Rollback removes the ordinary UI/API exposure and retains action/audit/provider evidence. It never
rolls back mailbox data or erases completed personal acknowledgements.

## Testing Plan

- Reader renders the split command on desktop/mobile and supports keyboard/touch without hover.
- The main button changes one message; the conversation command affects the exact six-member
  snapshot even when only one member matches the current list filter.
- Already-read selected message still allows acknowledging unread peers.
- Opening, hovering, previewing and cancelling do not acknowledge mail.
- A later arrival remains unread; another user's state and other accounts remain unchanged.
- Shared/delegated mail has personal-only effects; personal-owner provider results stay separate.
- Duplicates, revocation, changed epochs/bindings/UIDs, missing mail, partial/provider failures,
  repeated apply and larger queued continuation report honest, idempotent outcomes.
- Legacy/truncated groups never silently apply to a partial page.
- Ordinary browser/API parity works while advanced multi-account/default-off paths remain off.
- Run focused Dev tests and relevant conversation/unread/API regressions. Use synthetic provider
  tests; real-provider scenarios require their own controlled human review.

## Documentation Plan

Update Email Knowledge, module guidance, API/OpenAPI/parity documentation, TODO and the exact human
review entry. Publish/read back affected BookStack content with honest Dev/Main/production status
and existing employee scope. Add a public-safe website handoff only after implementation verification.

## Open Questions

No product question remains. The split-button and current-mailbox snapshot scope were approved.
Reversible implementation choices are owned by the implementer.

## Approval

Approved by Svein Tore in chat on 2026-10-21: "Godkjenner. Iverksett".
Approval authorizes implementation of this scoped workflow on Dev. Broader legacy reviews,
production activation, commit/push and Main promotion/deployment remain separate.

## Controlled Production Validation Authorization

Svein Tore explicitly requested Main promotion and production deployment on 2026-10-21 because
Dev does not receive incoming mail. This supersedes the earlier deployment hold for this scoped
addition; remaining real-mail checks stay In Review and move to production. It does not approve
the older advanced acknowledgement or collaboration runtime.

Production evidence: ../plans/2026-10-21-mail-conversation-read-production-deployment.md.
