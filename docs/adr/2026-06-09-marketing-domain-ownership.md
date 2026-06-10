# ADR: Marketing Domain Ownership

Status: Accepted
Date: 2026-06-09
Decision Makers: Svein Tore Ramstad / Codex

## Context

Nexum PSA needs email marketing, mailing lists, campaign automation, tracking, WordPress content
ingestion, future Google/social integrations, AI-assisted content and classification, and Sales
follow-up based on recipient engagement.

The existing Sales domain owns leads, opportunities, quotes, and sales activities. The Email domain
owns SMTP/IMAP accounts, email templates, rendering, inbox storage, health checks, and low-level
email sending. The Contact domain owns external people and communication endpoints.

Without a domain ownership decision, marketing campaign logic could be split across Sales, Email,
Contact, and Integration in ways that make automation, consent, tracking, and future social channels
hard to maintain.

## Decision

Create `Marketing` as its own domain/module.

Marketing owns:

- Mailing lists and resolved memberships.
- Campaigns and campaign email sequences.
- Campaign approvals and sending state.
- Recipient queue state and send attempts.
- Marketing tracking events, tracked links, opens, clicks, bounces, suppressions, engagement scores,
  consent categories, and interest tags.
- Marketing settings for consent defaults, unsubscribe behavior, send batching, and tracking.

Email continues to own:

- SMTP/IMAP accounts.
- Default Email account scopes, including the new `marketing` scope.
- Email templates, template rendering, brand-aware HTML wrappers, preview, and seeded templates.
- Low-level SMTP delivery services.

Contact continues to own:

- Contact identity, email addresses, phone numbers, relations, and future communication preferences.
- Compatibility with legacy `client_users` while the Contact migration continues.

Sales consumes marketing outcomes but does not own campaigns. Campaign engagement may create Sales
call lists, activities, lead updates, and interest context.

Integration owns external provider configuration such as WordPress, Google, and future social
providers.

## Rationale

Marketing is broader than Sales. It needs list management, consent policy, unsubscribe behavior,
campaign sequencing, tracking, bounces, content sources, and future social/Google channels. Keeping
that in Sales would make Sales responsible for campaign infrastructure that is not part of the sales
pipeline itself.

Marketing is also broader than Email. Email provides the delivery and template infrastructure, but
Marketing owns why a message is sent, who receives it, when it is sent, what sequence state exists,
and how engagement is interpreted.

This separation keeps each domain cohesive while allowing Marketing to reuse Contact identities,
Email delivery/templates, Integration provider settings, and Sales follow-up workflows.

## Consequences

Positive:

- Campaign automation has one clear owner.
- Email templates and SMTP remain reusable across Tickets, Sales, System, and Marketing.
- Consent and suppression can be enforced centrally in Marketing before each marketing send.
- Future WordPress, Google, social posting, AI, and Sales attribution can be added without bloating
  Sales or Email.

Negative:

- Marketing will touch several modules and requires careful feature slicing.
- Cross-module tests are needed as Marketing starts consuming Contact, Email, Integration, Sales,
  and Client data.
- Early slices must avoid exposing unfinished controls.

## Alternatives Considered

- Put marketing inside Sales. Rejected because campaigns, tracking, unsubscribe, and multi-channel
  publishing are not Sales pipeline concerns.
- Put marketing inside Email. Rejected because Email owns transport and templates, not audience
  strategy, campaign sequencing, recipient state, or Sales follow-up.
- Build only WordPress integration first. Rejected because the immediate production need is sending
  controlled email campaigns; WordPress content can feed Marketing after the first campaign model is
  stable.

## Follow-Up

- Implement approved RFC `2026-06-09-marketing-domain-email-campaigns.md` through feature slices.
- Add `marketing` Email account and Email template scopes before campaign sending.
- Keep WordPress integration as a later Integration-owned slice.
