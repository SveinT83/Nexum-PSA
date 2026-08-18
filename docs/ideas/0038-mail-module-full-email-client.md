# Mail Module - Full Email Client With PSA Integration

Status: approved
GitHub Discussion: https://github.com/SveinT83/Nexum-PSA/discussions/38
RFC: ../rfc/2026-07-04-mail-module-full-email-client.md
GitHub Category: In review
Source: GitHub Ideas import, 2026-07-04
Last reviewed: 2026-08-12
Affected modules: Email, Integration, UserManagement, Taxonomy, Contact, Clients, Relationship,
Ticket, CustomerPortal, Sales, Task, Signal, Notification, Calendar, Documentation, Report
Level: 3

## Summary

Build a full-featured Mail module that behaves like a classic desktop email client while adding
PSA-specific linking, rules, collaboration, and AI-assisted workflows.

The core principle from the discussion is: Mail is Mail. PSA behavior should enhance email workflow,
not replace it.

## Scope Notes

The discussion includes personal/shared/ticket mailboxes, IMAP/SMTP, future Microsoft/Google
providers, folder sync, drafts, sent items, trash, unread view, conversation view, message
correlation, rules, tags, categories, attachments, full-text search, AI suggestions, calendar
invites, PSA object linking, live collaboration, shared-draft locking, and responder reservation.

The 2026-08-11 clarification makes every external mailbox server-authoritative and bidirectionally
synchronized across enabled folders. Nexum is the cache, search, rules, and PSA layer rather than a
competing mailbox copy. Remote deletion removes the Mail placement/cache under Email policy but not
documentation explicitly captured into Ticket under Ticket retention.

Shared addresses are real IMAP/SMTP accounts configured under Admin with independent `view`,
`organize`, and `send` grants. The default `/tech/mail` surface uses Livewire 3 to show one live,
permission-filtered `unread for me` conversation Inbox across all real accounts the technician may
view, with no manual refresh and a resilient automatic fallback.

Opening mail records authorized `opened by` collaboration state but never marks it read. Personal
manual read state, provider `Seen`, durable opened-by history, and expiring reading/typing presence
remain separate. Mail reuses Taxonomy categories/tags at the account-conversation boundary.

An Email-based Ticket is a case layer over one or more real Email conversations. Source mail remains
in its provider Inbox; Ticket replies use the same Email outbound/Sent lifecycle; standards-based
headers extend rather than replace the existing `TD-...` Ticket-number logic. One Ticket may contain
several customer/supplier/provider conversations, while each conversation has only one primary
automatic-routing Ticket. Every reply stays inside its selected conversation/audience, and newly
linked third-party correspondence is internal-only in the customer portal until explicitly published.

The target security floor requires verified provider transport/endpoints, attachment quarantine,
local or installation-controlled search by default, immediate access/send revocation, and explicit
cache/index/AI-artifact, Ticket-evidence, legal-hold, DSAR/export, backup, and restore lifecycles.

The 2026-08-11 extension adds a personal **Rules & AI** workspace per technician/account, API-first
rules with preview/version/reprocessing/audit, governed summaries and reply drafts, supervised inbox
cleanup, and separately gated automatic replies.

## Review State

- GitHub Discussion #38 remains externally categorized as `In review`; no GitHub write was made by
  this local approval update.
- Svein explicitly approved the complete Level 3 RFC on 2026-08-12.
- Four accepted ADRs cover Email domain ownership, canonical messages/mailbox placements, mailbox
  access/rule authority, and Email conversations as Ticket communication channels.
- Historical issue #121 is closed as `not planned`; it remains source material for the first Feature
  Slice and must not be reopened automatically.
- No implementation or Feature Slice is active yet. The next selected work must begin with the first
  scoped Feature Slice on authoritative Dev; automatic external replies remain separately gated.
