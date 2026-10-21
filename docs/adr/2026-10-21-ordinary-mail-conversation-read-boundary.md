# ADR: Ordinary Mail Conversation Read Uses A Distinct Frozen Scope

Status: Accepted
Date: 2026-10-21
Decision Makers: Svein Tore (approved RFC) / Codex (implementation)
Related RFC: ../rfc/2026-10-21-mail-conversation-read-command.md

## Decision

Reuse the installed conversation action run/item ledgers with a distinct immutable scope_kind
ordinary_conversation_read. Preview derives one complete active-account durable conversation from
an authorized exact placement. The target is always personal read; provider Seen is derived from
personal ownership, never caller-supplied. Existing advanced acknowledgement and explicit
multi-account paths keep their default-off flag. Apply/cancel/continuation admit the new scope only
with one frozen account/conversation and a read target; every item is still reauthorized.

No schema migration or alternate read-state implementation. Legacy groups without complete durable
membership reject the all action before mutation instead of silently acknowledging a partial page.
This is the approved RFC's incomplete-scope behavior.

Personal and provider results remain separate. Shared/delegated read never writes provider Seen
implicitly. A personal owner's provider authority and ownership are checked again when applying.
The main button remains the existing selected-message command. Opening/hovering never acknowledges.
No production activation or broader human-review approval is inferred.
