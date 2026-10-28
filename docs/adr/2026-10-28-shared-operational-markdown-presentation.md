# ADR: Shared Operational Markdown Presentation

Status: Accepted
Date: 2026-10-28
Decision Makers: Svein Tore (audited fix/Main authorization), Codex (technical defaults)
Parent: RFC 2026-10-28-task-ticket-markdown; Issue #328.

## Decision

System owns a pure shared presentation action using installed CommonMark; a global Blade
component reuses it in Task/Ticket. Source stays Markdown text in existing fields/API payloads.
Escape raw HTML, reject unsafe links, render image alt text without loading images, bound nesting
and escape on conversion failure. No access check is replaced by formatting.

Email retains template/branding/transport ownership. Optional HTML-only variable overrides leave
subject/plain-text variables unchanged. Only Ticket jobs supply already-sanitized Markdown HTML;
all existing template calls without overrides preserve their behavior. No stored template rewrite.

## Rationale And Alternatives

One renderer avoids divergent Task/Ticket/Portal/email rules. Replacing text with stored HTML,
building a separate rich editor, using unrestricted raw HTML or changing unrelated Knowledge/Mail
renderers would add migration, authority or compatibility risk beyond the requested formatting.

## Consequences

Markdown syntax is visible when editing or reading raw APIs/plain MIME. Historical text may be
interpreted as Markdown on the affected read surfaces but is never rewritten. Attachments remain
separate and guarded. Main can be tested/merged under Svein's explicit request; named production
human review remains Pending. No schema or recipient/sender/visibility change.
