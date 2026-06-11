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
- Manual Contact additions from existing Contacts with active email addresses.
- Campaign draft, approval, recipient queue, due send job, and open/click/unsubscribe tracking
  foundation.
- Campaign sequence editing for multiple ordered emails, delay/scheduled timing, subject override,
  and active-campaign recipient queue sync.
- Dashboard summaries for campaigns, recipients, mailing lists, sender setup, templates, queue, and
  tracking activity.
- Admin settings for consent, unsubscribe, active-contract eligibility, tracking defaults, quiet
  hours, and send batching.

The Email foundation slices add the `marketing` sender scope, `marketing` Email template scope,
brand-aware HTML rendering, template preview, and a seeded default marketing campaign template.

WordPress content pull, Google integrations, social publishing, and AI-assisted content tooling are
planned follow-up slices. Separate Marketing engagement/call lists are intentionally out of scope;
marketing interest should be consumed by Sales/Leads sorting, Lead Heat, and classification.

## Mailing Lists

Technicians with `marketing.list.manage` can create lists from `/tech/marketing/lists`. The current
audiences are `all_business_contacts` and `manual_contacts`. The all-business audience can be
segmented by shared Contact and Client tags. Either audience can include manually selected existing
Contacts.

List resolution includes:

- Active Contacts with at least one email address.
- Contacts that are not marked `do_not_email`.
- Contacts with `marketing_consent=true` when Marketing settings require explicit opt-in.
- Legacy `client_users` without a linked Contact while compatibility migration continues.
- Selected Contact tags, when the list has Contact tag criteria.
- Selected Client tags, when the list has Client tag criteria.
- Selected manual Contacts, when the list has manual Contact criteria.

List membership is materialized in `marketing_list_members`. This gives campaigns a stable recipient
snapshot and lets operators refresh the list when Contacts change. Duplicate email addresses are
collapsed before members are stored.

Contact tag criteria only match first-class Contacts. Client tag criteria match Contacts related to
tagged Clients and legacy `client_users` that belong to tagged Clients.

Manual Contact criteria resolve only first-class Contacts. They use the same eligibility rules as
automatic segments, so opted-out, inactive, or email-less Contacts are not materialized as
recipients even if their ID remains in the saved list criteria.

Default consent categories are seeded on Marketing page access: Newsletter, Security, Websites, and
Cloud. Default interest tags prepare later open/click tracking and Sales categorization.

## Campaigns

Marketing campaigns are created as drafts from a mailing list and an active Email template with the
`marketing` scope. Each campaign can choose a sender account. If it does not, Marketing uses the
active Email account marked as default for the `marketing` scope.

A campaign must be approved by a technician with `marketing.campaign.approve` before sending. On
approval, Marketing creates `marketing_campaign_recipients` rows from the current resolved list
members. The queue stores due time, status, attempts, message id, and tracking token.

Campaigns can contain multiple ordered emails. Technicians with `marketing.campaign.edit` can add a
new sequence email, change order, change delay or scheduled time, set subject override, and set an
email active or inactive. Delay and schedule changes update pending recipients only. Sent recipient
history is kept. If a sent sequence email is removed, the email is deactivated and pending recipients
are cancelled; unsent sequence emails are deleted with their pending queue rows.

Adding a sequence email to an approved or active campaign immediately creates pending recipient rows
for current eligible list members, so existing contacts can receive new follow-up emails without
recreating the campaign.

The scheduled Marketing job runs every minute and sends due recipients in batches. The manual command
is:

```bash
php artisan marketing:send-due
```

Open tracking records a public pixel request. Click tracking records the click and redirects the
recipient to the original URL. Clicks are classified against active Marketing interest tags when the
URL or campaign context clearly matches subjects such as security, websites, or cloud.

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
