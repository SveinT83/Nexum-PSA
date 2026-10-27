# ADR: Documentation API Authority And Revision Boundary

Status: Accepted within the directly authorized parity scope
Date: 2026-10-27
Decision Makers: Svein Tore (scope) / Codex (implementation)

## Context
Documentation owns vendors, carriers and mutable documents. Knowledge owns the
existing immutable Knowledge revisions. #275 requests a distinct future lifecycle.

## Decision
Keep current Documentation operations authoritative in its own module. Preserve
legacy knowledge.* API scopes while enforcing current matching web actor rights.
Use Documentation-owned local encrypted commands, row locks and exact read-back.
Share visibility and update side effects with web. Hide content on audience changes
until explicitly made visible again. Portal reads reuse current membership/scope.
Expose no pretend Documentation revision or approval operations.

## Rationale
The API must provide all supported current behavior without creating a second
supplier register, weakening portal access, or bypassing #275 when it is implemented.

## Consequences
One additive command migration; receipts depend on APP_KEY. Existing clients retain
URLs/scopes but must possess actual actor permissions. Multiple templates require
explicit selection per the approved 2026-07-29 RFC. Commands are not revision history.
Singleton API serialization favors integrity over unmeasured high-volume throughput.

## Alternatives Considered
Raw model APIs and forwarding browser redirects lack stable contracts. Reusing
Storage command tables mixes ownership. Implementing #275 here would require
unapproved publication/lifecycle decisions beyond current browser parity.

## Follow-Up
Keep #275 open. Future revision Actions must own both browser and API mutations
and preserve exact revision/conflict/read-back and #276 downstream sync rules.
