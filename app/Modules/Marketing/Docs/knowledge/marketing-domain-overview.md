The Marketing domain owns mailing lists, campaign automation, recipient state, tracking, suppression,
consent policy, and interest tags. Sales consumes marketing interest inside Lead Heat and
classification workflows.

Marketing is intentionally separate from Sales and Email.

Sales owns leads, opportunities, quotes, and sales activities. Email owns SMTP/IMAP accounts,
outbound templates, template rendering, health checks, and low-level delivery. Marketing uses those
capabilities to decide who receives campaign messages, when messages are sent, how approvals work,
and how engagement becomes useful Sales/Leads context.

## Current Status

The first slices create the Marketing and Email foundation:

- `/tech/marketing` email marketing dashboard.
- `marketing.view` permission.
- Future Marketing permissions for lists, campaigns, approval, sending, analytics, and settings.
- Sales workspace navigation entry.
- Approved RFC and ADR documentation.
- Marketing list tables, default consent categories, default interest tags, list UI, and resolved
  list membership.
- Mailing list audience modes for all business contacts or manually selected Contacts only.
- Mailing list editing plus manual Contact additions/removals from existing Contacts with active
  email addresses, and guarded deletion for lists that are not used by campaigns.
- Campaign draft, approval, recipient queue, due send job, and open/click/unsubscribe tracking
  foundation.
- Campaign sequence editing for multiple ordered campaign emails as collapsible cards with
  campaign-level send rhythm, optional extra delay, editable email subject/body snapshots, preview,
  test-send, new-contact policy, recipient batch throttling, and active-campaign recipient queue
  sync.
- AI-assisted campaign planning from campaign, list, available template, and current sequence
  context when an active AI agent is available.
- AI-assisted campaign email drafting from campaign, list, and current email context when an active
  AI agent is available.
- Dashboard summaries for campaigns, recipients, mailing lists, sender setup, templates, queue, and
  tracking activity.
- Admin settings for consent, unsubscribe, active-contract eligibility, tracking defaults, quiet
  hours, and send batching.

The Email foundation slices add the `marketing` sender scope, `marketing` Email template scope,
brand-aware HTML rendering, template preview, and a seeded default marketing campaign template.

WordPress content pull, Google integrations, social publishing, and richer content tooling are
planned follow-up slices. Separate Marketing engagement/call lists are intentionally out of scope;
marketing interest should be consumed by Sales/Leads sorting, Lead Heat, and classification.

## Mailing Lists

Technicians with `marketing.list.manage` can create and edit lists from `/tech/marketing/lists`. The
current audiences are `all_business_contacts` and `manual_contacts`. The all-business audience can
be segmented by shared Contact and Client tags. Either audience can include manually selected
existing Contacts.

List resolution includes:

- Active Contacts with at least one email address.
- Contacts that are not marked `do_not_email`.
- Contacts with `marketing_consent=true` when Marketing settings require explicit opt-in.
- Legacy `client_users` without a linked Contact while compatibility migration continues.
- Selected Contact tags, when the list has Contact tag criteria.
- Selected Client tags, when the list has Client tag criteria.
- Selected manual Contacts, when the list has manual Contact criteria.
- Contacts removed from this specific list are excluded from future refreshes until they are added
  again.

List membership is materialized in `marketing_list_members`. This gives campaigns a stable recipient
snapshot and lets operators refresh the list when Contacts change. Duplicate email addresses are
collapsed before members are stored. Refresh rebuilds criteria-driven members, but preserves members
promoted by Lead Intelligence unless the Contact has been removed from that specific list.

The list detail page lets list managers add eligible existing Contacts and remove resolved
first-class Contacts. Removal is a Marketing list exclusion, not a Contact preference change; it does
not set `do_not_email` or change global consent.

The edit page lets list managers delete unused mailing lists. Deletion removes the list and its
resolved members, but never deletes Contacts. Lists used by campaigns are protected from deletion so
campaign sequence, recipient, and tracking history is preserved.

Contact tag criteria only match first-class Contacts. Client tag criteria match Contacts related to
tagged Clients and legacy `client_users` that belong to tagged Clients.

Manual Contact criteria resolve only first-class Contacts. They use the same eligibility rules as
automatic segments, so opted-out, inactive, or email-less Contacts are not materialized as
recipients even if their ID remains in the saved list criteria.

Default consent categories are seeded on Marketing page access: Newsletter, Security, Websites, and
Cloud. Default interest tags prepare later open/click tracking and Sales categorization.

## Campaigns

Marketing campaigns are created as drafts from a mailing list, send rhythm, sender account, and
send preferences. Campaign emails are added after the campaign exists from the campaign detail page.
Each campaign email uses an active Email template with the `marketing` scope as its starting point,
then stores its own subject, HTML body, plaintext body, and template metadata as a snapshot. Each
campaign can choose a sender account. If it does not, Marketing uses the active Email account marked
as default for the `marketing` scope.

A campaign must be approved by a technician with `marketing.campaign.approve` before sending. On
approval, Marketing creates `marketing_campaign_recipients` rows from the current resolved list
members. The queue stores due time, status, attempts, message id, and tracking token.

Campaigns can contain multiple ordered emails. Technicians with `marketing.campaign.edit` can add a
new sequence email from a start template, edit the campaign email name, subject, HTML body, plaintext
body, order, optional extra delay, and active/inactive status. Existing emails are displayed as
cards with recipient, sent, open, and click counts, then expanded for preview, test-send, AI
drafting, and editing. New emails are behind a button; selecting a start template fills the editable
snapshot fields before save.

Campaign-level schedule controls the normal ordered sequence. Operators choose a send rhythm such
as daily, weekly, monthly, or a custom interval. The schedule form also exposes first send date, send
time, and for weekly campaigns the weekday, so a campaign can be set to send every Friday at 12:00
without interpreting technical cadence fields. A sequence email's delay is only extra delay on top
of that campaign rhythm. Updating the campaign schedule recalculates pending recipient due times.
On the campaign detail page, schedule and recipient queue panels are collapsed by default so the
ordered campaign emails remain the primary work surface.

Recipient throttling is separate from sequence timing. Batch size controls how many recipients are
due at the same time for a campaign email, and batch interval minutes space later recipient batches.
New contacts can either start at the first sequence email, or join the current schedule so newsletter
subscribers do not receive old campaign emails. Sent recipient history is kept. If a sent sequence
email is removed, the email is deactivated and pending recipients are cancelled; unsent sequence
emails are deleted with their pending queue rows.

Campaign emails send from their stored snapshot, not from the live Email template. This means an
administrator can later change a reusable Marketing template without silently changing draft,
approved, active, or historical campaign emails that were already created from it.

Adding a sequence email to an approved or active campaign immediately creates pending recipient rows
for current eligible list members, so existing contacts can receive new follow-up emails without
recreating the campaign.

Preview uses the editable HTML body in the campaign email form. Known recipient/company
placeholders such as `contact_name`, `client_name`, `company_name`, and `unsubscribe_url` are
rendered with real campaign/list context when available, then clear sample values. Unknown
placeholders stay visible in preview so operators can see that the system does not know what data
should replace them. Test-send uses the current editor fields and sends through the campaign sender
account or the default `marketing` account. The test recipient defaults to the current technician
email address and can be overwritten for a colleague. AI planning and drafting open from compact
icon controls on the campaign and email editor surfaces, so the prompt is hidden until a technician
asks for AI help. Marketing seeds a Marketing Campaign Agent and attaches it to the first active
Integration AI provider when one exists. Without an active provider/agent, the assist controls stay
visible but their prompt actions are disabled. Campaign-level AI returns a draft campaign plan and
proposed email sequence; email-level AI returns editable form fields for the current email draft. AI
actions are audited as AI chats, and the technician still decides whether to save the result.
AI-generated marketing emails are expected to include `unsubscribe_url` in HTML and plaintext so
operators can see the unsubscribe footer in preview. The send job appends a fallback unsubscribe
footer only when the rendered email does not already contain the recipient unsubscribe URL.
External website fetching is not implemented yet. URLs in prompts are treated as destination links
or brand hints only until a future content-source integration fetches and stores that content.

The scheduled Marketing job runs every minute and sends due recipients in batches. The manual command
is:

```bash
php artisan marketing:send-due
```

Open tracking records a public pixel request. Click tracking records the click and redirects the
recipient to the original URL. When click tracking is enabled, normal `http` and `https` links in a
campaign email are rewritten at send time, including WordPress links and links to unrelated external
sites, so the click can be reported back to Nexum PSA before the recipient is redirected. Clicks are
classified against active Marketing interest tags when the URL or campaign context clearly matches
subjects such as security, websites, or cloud.

Matching interest is stored in `marketing_interest_assignments` for the linked Contact and Client.
Each assignment keeps first and last event references, event count, engagement score, and last
matched URL. Campaign detail pages show interest signal counts for tracked events in that campaign.

Unsubscribe records the event and marks linked Contacts as `do_not_email`.

Marketing campaign events also write normalized records to the Signal domain. Signal rules can tag
Contacts or Clients, suppress marketing email, emit derived signals, or call webhooks. Marketing
lists remain the owner of audience selection and can later use those tags during refresh.

## Settings

Marketing settings are available under Admin > Marketing.

The settings control:

- Whether contacts are included by default unless they opt out, or require explicit opt-in.
- Whether unsubscribe applies to all marketing or the campaign category direction.
- Whether contacts related to clients with active contracts are eligible for marketing lists.
- Default open/click tracking values for new campaigns.
- Default batch size and send interval for campaigns.
- Quiet hours. Due recipients remain pending while quiet hours are active.
- Unsubscribe footer text appended to HTML and plaintext campaign email.

## Domain Ownership

Marketing owns:

- Mailing lists and segmentation.
- Campaigns and ordered campaign emails.
- Campaign-specific email subject/body snapshots.
- Campaign approvals.
- Recipient queue state and send attempts.
- Opens, clicks, bounces, suppressions, engagement scores, consent categories, and interest tags.
- Settings for consent defaults, unsubscribe behavior, send batching, and tracking defaults.

Email owns:

- Sender accounts and the `marketing` default account scope.
- Email templates and the `marketing` template scope.
- Brand-aware HTML rendering, preview, and seeded default marketing templates.
- Low-level SMTP sending.

Contact owns:

- Contact identity and communication endpoints.
- Client/contact relations.
- Future communication preferences.
- Legacy `client_users` compatibility while migration continues.

Sales consumes Marketing outcomes through lead context, Lead Heat, classification, and interest
signals. Sales does not own campaign execution, and Marketing does not own seller call lists.
