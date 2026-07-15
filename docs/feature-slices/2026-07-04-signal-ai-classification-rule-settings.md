# Feature Slice: Signal AI Classification And Rule Settings

Status: Done
Date: 2026-07-04
Parent: `docs/rfc/2026-06-09-signal-domain-active-automation.md`
Owner: Codex

## Goal

Finish the remaining Signal automation slice with AI-assisted classification and admin-configurable
rule/action settings.

## User-Visible Behavior

Admins can configure Signal AI classification policy from the Signal settings page. Signal rules can
be created and edited through structured condition and action fields while still storing normalized
JSON behind the rule engine.

## Scope

- Signal settings stored in `common_settings`.
- Signal-specific AI agent defaulting through the existing Integration AI provider model.
- Optional AI classification fallback for inbound Email signals after deterministic classifiers run.
- Configurable ticket-routing stop types for recorded machine signals.
- Structured rule condition and action form parsing.

## Out Of Scope

- AI action execution.
- AI-created tickets or contacts without Signal rules.
- New external providers outside the existing Integration AI provider system.
- Replacing deterministic bounce, autoreply, unsubscribe, or vendor classifiers.

## Data Touched

- `common_settings` with `type=signal`, `name=settings`.
- Existing `signals`, `signal_rules`, and `ai_agents` records.
- Inbound Email processing reads the Signal settings and can record AI-classified Signal records.

## Permissions

Settings use existing `signal.rule.manage`. Signal feed access remains `signal.view`.

## Tests

- Signal default AI agent creation.
- Signal settings update.
- Structured rule creation.
- AI-assisted inbound Email classification before ticket routing.
- Existing Signal rule execution tests remain covered.

## Documentation

Updated Signal README, Signal Knowledge documentation, this feature slice, and `docs/TODO.md`.

## Done Criteria

- Admin settings are functional and permission protected.
- AI classification is disabled by default and grounded by settings.
- Structured rule fields persist normalized conditions/actions.
- Inbound Email AI classification records Signal events and can skip ticket routing for configured
  machine signal types.
- Relevant tests pass on Dev.
