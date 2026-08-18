# Feature Slice: Email Mail Write-Gated AI Assistants

Status: Done
Date: 2026-08-13
Parent: `docs/rfc/2026-07-04-mail-module-full-email-client.md`
Owner: Svein / Codex

## Goal

Allow Mail AI to support a small, explicit write-assisted action without giving agents unrestricted
write access or bypassing ordinary user permissions.

## User-Visible Behavior

Mail AI summary remains read-only by default. When a summary is visible, the selected Mail agent is
active, governance is approved, the agent has action execution enabled, the agent has the required
Ticket API write scopes, and the signed-in user has normal Ticket and mailbox permissions, the AI
summary panel can show `Create Ticket`. The technician must click it. Nexum then creates and links
the Ticket through the same deterministic Mail-to-Ticket flow used by the ordinary Ticket icon.

The AI model does not send email, move mail, call arbitrary tools, or mutate tickets directly from
text output.

## Scope

- Add Mail AI write availability checks to `MailAiAgentRuntime`.
- Require `can_execute_actions` and explicit allowed API scopes for write-assisted Mail actions.
- Add AI-gated Ticket creation button in the summary panel.
- Reuse existing Mail-to-Ticket creation and conversation-link logic.

## Out Of Scope

- AI-generated automatic replies or sending.
- AI moving mail, creating rules, applying tags, or updating arbitrary Ticket fields.
- Background AI agents that act without a technician click.

## Data Touched

- Existing AI provider/agent governance data.
- Existing Ticket, Email message, Ticket message, and email conversation-link rows only after an
  explicit user click.

## Permissions

The write gate requires all of:

- governed Mail AI availability,
- an action-enabled selected Email/default agent,
- agent API scopes `tickets.create` and `tickets.update`,
- user `ticket.create`,
- mailbox Organize access for the selected email.

## Tests

- The AI Ticket button stays hidden when the Mail agent is not action-enabled.
- Enabling agent actions with Ticket write scopes shows the button.
- Clicking the AI-gated button creates and links a Ticket through the existing Mail flow.

## Automated Verification

- Focused Mail regressions including this slice passed on Dev with 6 tests and 50 assertions.
- Full `EmailModuleTest.php` passed on Dev with 120 tests and 1016 assertions. Dev migration, cache clearing, Blade cache, Email Knowledge sync, one queue-worker pass, no failed jobs, route registration, and git diff checks were also completed.

## Done Criteria

- Mail AI write actions are invisible unless every governance, agent, user, and mailbox gate passes.
- The first write-gated Mail AI action is deterministic and human-clicked.
- Existing read-only summary and reply drafting behavior remains unchanged.
