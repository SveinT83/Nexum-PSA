# RFC: Marketing Domain And Email Campaign Automation

Status: Approved
Date: 2026-06-09
Owner: Codex

## Context

Nexum PSA needs a production-ready marketing capability. The immediate need is email marketing:
build mailing lists from contacts, create templates, create campaigns, schedule campaign emails, and
send them automatically through existing SMTP accounts. The longer-term direction also includes
WordPress content ingestion, Google integrations, social publishing, AI-assisted content and
classification, Sales/Leads interest consumption, and interest scoring.

## Scope Amendment 2026-06-10

Marketing must not build a separate engagement or seller call-list workflow. Marketing records
campaign engagement, interest tags, and campaign analytics. Sales/Leads consumes that data for Lead
Heat, classification, filtering, sorting, and seller follow-up. The immediate product focus is
email marketing frontend completion: dashboard, campaign sequencing/lifecycle controls, email
design, send preferences, and reliable autonomous campaign sending.

## Scope Amendment 2026-06-11

Campaign emails are no longer live sends of Email template records. A Marketing campaign email uses
an Email template as a starting point, then stores its own subject, HTML body, plaintext body,
template source name, and template variables as a snapshot. This keeps approved and active campaign
emails stable if an administrator edits the original Email template later. The Email module still
owns reusable templates and rendering infrastructure; Marketing owns the campaign-specific email
snapshot and sequence state.

Campaign detail editing should treat existing campaign emails as collapsed cards, not as one large
always-visible form. Creating a new campaign email is behind an explicit button. Selecting a start
template fills the editable snapshot fields before saving. Campaign start time and per-email delay
are the primary scheduling controls; per-email absolute schedule fields should not be exposed in the
UI until a later lifecycle feature needs them. Preview, test-send, and AI-assisted drafting are part
of the email-first frontend slice. AI drafting is a form-assist workflow: it may use campaign/list
context and current email content, but it does not send, approve, or save without technician action.

This is a Level 3 change because it introduces a new domain, database tables, permissions,
automation, outbound email behavior, tracking endpoints, integrations, and cross-module workflows
with Contact, Email, Sales, Integration, and future Signal/AI features.

## Goals

- Create a new singular `Marketing` module for campaign and list ownership.
- Let technicians with explicit permissions create marketing lists from all eligible contacts.
- Let lists include Contacts and legacy `client_users` compatibility records while Contact migration
  continues.
- Let existing business contacts be included in marketing campaigns, including contacts related to
  Clients with active contracts, so newsletters and services they do not currently use can be
  marketed.
- Support metadata-based list building and segmentation.
- Use existing Email SMTP accounts for sending.
- Add `marketing` as a default Email account scope.
- Use and improve the existing Email template system for marketing email templates.
- Support branded HTML email templates with clean plaintext fallback.
- Seed a default marketing campaign email template so the system works immediately after install.
- Support campaign sequences and multiple campaign emails sent in order.
- Require campaign approval by a technician with the correct permission before automated sending.
- Let approved campaigns continue automatically after approval.
- Send already-released sequence emails to newly added eligible contacts.
- Support scheduling, throttling, and batch sizes per campaign or global marketing settings.
- Track delivery attempts, bounces, opens, and link clicks from the first version.
- Prepare inbound bounce/autoreply classification for the planned Email Signal capability.
- Capture recipient interest signals so Sales/Leads can adjust Lead Heat, classification, filtering,
  sorting, and follow-up context.
- Support categorization of both Contact and related Client based on engagement.
- Support multiple marketing consent and interest categories from the start.
- Automatically tag/categorize both Contact and related Client when tracked engagement identifies a
  topic of interest.
- Let each campaign override the sender Email account while defaulting to the Email account marked
  for `marketing`.
- Design the content model so WordPress content can be pulled later without changing campaign data.
- Keep AI optional but first-class in the data flow so AI can assist with content generation,
  segmentation, categorization, and follow-up recommendations.

## Non-Goals

- Do not implement social posting in the first slice.
- Do not implement Google Ads, Google Analytics, or Google Business Profile in the first slice.
- Do not require WordPress sync before the first manual email campaign can be sent.
- Do not replace the Email module's SMTP/IMAP account, template, rendering, or health-check
  ownership.
- Do not move Contact identity ownership into Marketing.
- Do not move Sales opportunities, quotes, or lead pipeline ownership into Marketing.
- Do not send unapproved campaigns automatically.

## Current Behavior

- The Contact domain is the long-term canonical identity layer for external people.
- Existing `client_users` remain a compatibility layer while dependent modules migrate.
- The Email domain owns IMAP/SMTP accounts, health checks, inbound storage, SMTP sending services,
  and default scopes such as `tickets`, `sales`, and `alerts`.
- The Sales domain owns leads, opportunities, quotes, and sales activities.
- The Integration domain owns external service configuration and provider-specific settings.
- There is no Marketing domain, mailing list model, campaign sequence model, tracking endpoint, or
  marketing automation engine today.

## Proposed Change

Create a new `app/Modules/Marketing` module with routes, controllers, views, actions, jobs, tests,
and documentation following the module architecture standard.

Marketing owns these concepts:

- Marketing lists.
- Segment criteria and resolved list memberships.
- Campaigns.
- Campaign emails in an ordered sequence.
- Marketing-specific usage of Email templates.
- Campaign approvals.
- Recipient queue state.
- Delivery attempts.
- Tracking events for opens and link clicks.
- Bounce and suppression records.
- Engagement and interest classifications.

Contact owns identity and communication preferences:

- Contact email addresses.
- Client/contact relations.
- Consent/opt-out flags and future communication preferences.
- Legacy compatibility with `client_users`.

Email owns sending infrastructure:

- SMTP accounts.
- Account health.
- `DefaultEmailAccountResolver`.
- Low-level SMTP send service.
- Inbound mailbox polling and stored inbound messages.
- Email template storage, rendering, brand-aware HTML wrappers, preview, and seeded default
  marketing templates.

Integration owns external provider configuration:

- WordPress connection settings and API client.
- Later Google and social provider settings.

Sales consumes marketing outcomes:

- Campaign engagement can influence Lead Heat, classification, lead updates, activity context, and
  interest tags inside the Sales/Leads module.
- Sales should not own campaign execution.
- Marketing should not own separate seller call lists.

### Campaign Flow

1. A technician creates or edits Email templates with the `marketing` scope.
2. A technician creates a marketing list using metadata filters.
3. The list resolves recipients from Contacts and legacy `client_users`.
4. A technician creates a campaign and adds one or more ordered campaign emails.
5. Each campaign email selects an active Email template with `marketing` scope as its starting
   template, then stores the editable campaign-specific subject and body snapshot.
6. The campaign start time and each campaign email's relative delay determine recipient due time.
7. A technician with approval permission approves the campaign.
8. The campaign engine creates recipient states and dispatches queued send jobs.
9. Jobs send in configured batches through the default `marketing` Email account or selected
   campaign sender account.
10. When a new contact enters an active list, they receive campaign emails that have already been
   released if they are still eligible and not suppressed.
11. Opens, clicks, bounces, and inbound automated replies update engagement data.
12. Engagement data can categorize the Contact and related Client and feed Sales/Leads heat,
    classification, filtering, and sorting.

### List And Segmentation Direction

Lists should support:

- Manual membership.
- Dynamic metadata filters.
- Client relation filters.
- Contact source/reference filters.
- Contact custom fields.
- Client custom fields.
- Client status/format filters.
- Marketing consent category filters.
- Marketing interest category filters.
- Opt-in/opt-out/suppression exclusions.
- Future engagement filters such as opened/clicked topic categories.

Resolved membership should be materialized for auditability and send stability. Dynamic criteria can
refresh membership through a job, but a campaign send should reference the resolved recipient state
used for that send.

### Tracking Direction

Tracking must exist from the first version because marketing data will drive phone lists, Sales
processes, and AI categorization.

Open tracking should use a per-recipient tracking pixel route.

Click tracking should rewrite campaign links through a signed/tokenized redirect route that records:

- Campaign.
- Campaign email.
- Recipient.
- Contact or legacy client user source.
- URL.
- Timestamp.
- Topic/category metadata when available.

Any normal `http` or `https` link in a campaign email, including WordPress article links and links
to third-party sites, should be reportable back to Nexum PSA when click tracking is enabled. Authors
and AI tools should write normal destination links; Marketing owns rewriting those links through the
tracking redirect at send time and recording the original URL before redirecting the recipient.

Tracked topic/category metadata should automatically update Contact-level and Client-level interest
tags. Sales/Leads views can then prioritize recipients and companies based on the interests they
actually engaged with, without creating a separate Marketing-owned call-list surface.

Bounce and automatic reply tracking should start with explicit data models and basic inbound
classification hooks. The first implementation can record manual/system-detected bounce events and
prepare the interface for the planned Email Signal reader.

### AI Direction

AI support should be built as an optional layer over stored marketing data, not as a hard dependency
for sending.

Planned AI use cases:

- Plan a full campaign from a campaign-level prompt, existing campaign/list context, current sequence
  emails, available Marketing templates, and later WordPress content sources. The first slice should
  return a draft plan only; technicians still choose which suggested emails to save and campaigns
  still require approval before sending.
- Draft campaign email copy from templates, campaign/list context, WordPress content when available,
  and company profile.
- Edit an existing campaign email draft from the current subject/body, campaign context, list
  context, future WordPress content context, and clear link-tracking rules.
- Suggest subject lines and plaintext fallbacks from the editable HTML draft.
- Summarize WordPress content into campaign content blocks.
- Classify link topics and recipient interests.
- Recommend Sales/Leads follow-up context.
- Categorize Contacts and related Clients based on engagement.
- Help build segment criteria in a controlled UI.

AI decisions must be auditable and must not bypass permissions, unsubscribe rules, or campaign
approval.

### Shared Content Editor Direction

Marketing email editing should later use a reusable WordPress-like editor rather than plain HTML
textareas. The editor should support visual block editing, drag/drop content blocks, HTML/source
editing, reusable template sections, preview, and safe output. It should be built as a shared
platform editor so the same editing surface can be reused by Marketing campaign emails, Email
templates, Documentation, Knowledge articles, and future content workflows.

## Impact Analysis

Affected modules:

- `Marketing`: new domain/module and primary owner of campaign workflows.
- `Contact`: recipient source, preferences, metadata, opt-out and client relations.
- `Email`: default `marketing` sender scope, SMTP delivery, outbound logs, Email templates,
  brand-aware rendering, preview, seeded marketing template, and inbound bounce/signal inputs.
- `Integration`: WordPress settings and later Google/social provider settings.
- `Sales`: Lead Heat, classification, filtering, sorting, activities, and lead/opportunity context
  from campaign engagement.
- `Client`: client-level categorization based on member contact engagement.
- `CustomField`: segment filters may use Contact and Client custom fields.
- `Notification`: optional internal notifications for campaign approval/failures later.

Permissions:

- `marketing.view`
- `marketing.list.manage`
- `marketing.campaign.create`
- `marketing.campaign.edit`
- `marketing.campaign.approve`
- `marketing.campaign.send`
- `marketing.analytics.view`
- `marketing.settings.manage`

Routes:

- Marketing tech/admin routes must live in `app/Modules/Marketing/routes.php`.
- Tracking routes should also be owned by Marketing.
- Public tracking routes must use opaque tokens and never expose internal IDs directly.

Queues and scheduler:

- Campaign list refresh jobs.
- Campaign release planner jobs.
- Campaign send batch jobs.
- Tracking aggregation jobs.
- Bounce/signal classification jobs when Email Signal is available.

UI:

- Marketing hub.
- Lists index/create/edit/show.
- Email template index/create/edit/preview with `marketing` scope and brand-aware HTML rendering.
- Campaign index/create/edit/show.
- Campaign approval controls.
- Campaign recipient status and analytics.
- Marketing settings.

Security and compliance:

- Suppression and unsubscribe rules must be enforced before every send.
- Every outbound marketing email must have an unsubscribe link.
- Marketing consent defaults must be settings-based. The default policy is business-to-business
  opt-out, meaning eligible business contacts may receive marketing until they unsubscribe or are
  suppressed.
- Unsubscribe behavior must be settings-based. The default is unsubscribe from all marketing, with
  support for category-specific unsubscribe later or when enabled.
- Campaign sender account selection must default to the Email account marked for `marketing`, but
  each campaign can override the sender account.
- Tracking must be configurable and transparent in operational docs.
- Batch limits and stop controls must exist to prevent accidental large sends.
- Public tracking endpoints must be abuse-resistant and not leak recipient details.

## Data And Migration Plan

Initial tables likely needed:

- `marketing_lists`
- `marketing_list_segments`
- `marketing_list_members`
- `marketing_campaigns`
- `marketing_campaign_emails`
- `marketing_campaign_recipients`
- `marketing_send_attempts`
- `marketing_tracking_events`
- `marketing_tracked_links`
- `marketing_suppressions`
- `marketing_consent_categories`
- `marketing_interest_tags`
- `marketing_engagement_scores`
- `marketing_settings`

Recipient references should support both Contact and legacy `client_users`, either through nullable
foreign keys plus source fields or a constrained polymorphic reference. The model should prefer
Contact where available and keep compatibility until the Contact migration is complete.

Marketing settings should include at least:

- Default consent mode: `opt_out` by default, with support for `explicit_opt_in` if policy changes.
- Default unsubscribe mode: `all_marketing` by default, with support for category-specific behavior.
- Whether contacts related to active-contract Clients are eligible by default.
- Required unsubscribe footer text and company contact details.
- Whether open and click tracking are enabled globally by default.
- Default campaign batch size and send interval.
- Default quiet hours or allowed send windows.

Email accounts:

- Extend Email account default scopes to include `marketing`.
- Existing `defaults_for` JSON can store the new scope.
- Existing SMTP account records do not need migration unless UI validation currently restricts scope
  values.
- Marketing campaigns should store an optional `email_account_id`; when blank, the campaign uses
  `DefaultEmailAccountResolver::forScope('marketing')`.

Email templates:

- Extend existing `email_templates` scope support with `marketing`.
- Keep template records owned by the Email module.
- Improve `EmailTemplateRenderer` so it can wrap HTML bodies in a shared brand-aware email layout.
- Add preview/sample-variable support so admins can see the rendered HTML before use.
- Add a seeded default marketing campaign template with subject, branded HTML, plaintext fallback,
  required unsubscribe footer variables, and tracking-ready link placeholders.
- Marketing campaign emails should keep the source Email template reference for traceability, but
  send from the campaign email snapshot so later template edits do not change active campaigns.

WordPress:

- Do not block first email marketing slice on WordPress tables.
- Add marketing content source fields from the start, for example `source_type`, `source_ref`,
  `source_url`, `source_checksum`, and `source_synced_at`.
- Add WordPress integration tables/settings in a later slice owned by Integration.

Rollback:

- Campaign sends and tracking data are audit records and should not be destructively rolled back in
  production.
- Migrations should be additive and safe for existing Contact, Email, and Sales data.

## Feature Slices

### Slice 1: RFC/ADR And Domain Skeleton

- Create Marketing module skeleton.
- Add permissions and navigation.
- Add Marketing settings stub only for implemented settings.
- Add Knowledge documentation.
- Add ADR for Marketing vs Sales ownership.

### Slice 2: Email Account Marketing Default

- Add `marketing` to Email account default scope UI and validation.
- Verify `DefaultEmailAccountResolver::forScope('marketing')`.
- Add tests in Email module.

### Slice 3: Email Branding And Marketing Template Readiness

- Add `marketing` to `EmailTemplate::SCOPES`.
- Add global branding variables and fallback values to `EmailTemplateRenderer`.
- Add a shared HTML email wrapper/layout for rendered HTML emails.
- Add template preview with sample variables and current company branding.
- Seed a default `marketing` campaign email template.
- Keep plaintext output readable.
- Add Email module tests for marketing scope, seeded template, renderer branding, and preview.

### Slice 4: Lists, Consent Policy, And Recipients

- Add marketing lists and resolved memberships.
- Support Contacts and legacy `client_users`.
- Support manual list membership and first metadata filters.
- Add settings-backed consent policy with `opt_out` as the default.
- Add marketing consent categories.
- Add settings-backed unsubscribe policy with all-marketing unsubscribe as the default.
- Enforce opt-out/suppression exclusions before every send.

### Slice 5: Campaign Drafts And Template Selection

- Add campaign drafts and ordered campaign emails.
- Let campaign emails select active `marketing` Email templates.
- Let campaigns select a sender Email account, falling back to the default `marketing` account.
- Render campaign previews through the Email template renderer.
- Add preview/test-send using Email SMTP account.
- Show existing campaign emails as collapsed cards and place new-email creation behind a button.
- Add an AI draft form-assist button when an active Integration AI agent is available.

### Slice 6: Approval And Automated Sending

- Add campaign approval.
- Add queue jobs for scheduled/batched sending.
- Add per-recipient sequence state.
- Ensure newly added contacts receive already released sequence emails.
- Add stop/pause controls.

### Slice 7: Tracking From Day One

- Add tracked links and open pixel.
- Add tracking event capture.
- Add analytics on campaign show.
- Add topic/category metadata for links.

### Slice 8: Bounce And Signal Preparation

- Add bounce/suppression models and inbound hooks.
- Integrate with Email inbox stored messages where possible.
- Prepare for planned Signal feature to classify bounces/autoreplies.

### Slice 9: Sales/Leads Interest Consumption

- Surface marketing engagement in Sales/Leads filtering and sorting.
- Feed Lead Heat and classification from tracked campaign interest.
- Add Sales activity or lead context where useful without moving campaign execution into Sales.
- Automatically categorize Contact and related Client based on tracked engagement topics.

### Slice 10: WordPress Content Pull

- Add WordPress integration settings and API client in Integration.
- Pull posts/pages/categories into Marketing content blocks.
- Allow campaign emails to use synced content.

## Testing Plan

- Feature tests for Marketing routes, permissions, lists, campaigns, template selection, approval,
  and send queue creation.
- Unit tests for segment resolution, suppression rules, schedule calculation, recipient sequencing,
  tracking token generation, and link rewriting.
- Email module tests for the new `marketing` default sender scope, `marketing` template scope,
  seeded marketing template, brand-aware renderer, and template preview.
- Integration tests using faked SMTP sender for campaign send jobs.
- Tests that unapproved campaigns do not send.
- Tests that campaigns can render the default marketing template after seeding.
- Tests that opted-out/suppressed recipients are skipped.
- Tests that newly added eligible contacts receive already released campaign emails.
- Tests that tracking routes record opens/clicks without exposing internal IDs.
- Tests that ordered campaign emails send in sequence.
- Tests for Contact and Client categorization side effects once implemented.

## Documentation Plan

- Add Marketing module README.
- Add Marketing Knowledge articles:
  - Marketing overview.
  - Mailing lists and segments.
  - Campaign approval and sending.
  - Tracking, bounces, and suppression.
  - WordPress content sync when implemented.
- Update Email Knowledge/docs for the `marketing` default sender scope, marketing template scope,
  branded HTML rendering, default seed template, preview, and supported variables.
- Update Contact docs for marketing preferences/suppression ownership.
- Update Sales docs when campaign engagement affects Lead Heat, classification, sorting, or Sales
  activities.
- Update Integration docs when WordPress settings are added.
- Update deployment docs for required queue/scheduler behavior.

## Open Questions

- Should click/open tracking be globally configurable, per campaign, or both?
- What initial metadata fields must list filters support first: Contact custom fields, Client custom
  fields, relation type, client format/status, source, or tags?
- Should campaign AI features use the future provider-neutral AI integration only, or is a temporary
  internal AI abstraction acceptable before that domain is complete?

## Approval

Approved by Svein Tore Ramstad on 2026-06-09.
