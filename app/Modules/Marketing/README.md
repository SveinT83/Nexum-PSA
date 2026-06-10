# Marketing Domain

The Marketing domain owns campaign and audience workflows for Nexum PSA.

Marketing is separate from Sales and Email:

- Sales owns leads, opportunities, quotes, and sales activities.
- Email owns SMTP/IMAP accounts, outbound templates, rendering, and low-level delivery.
- Contact owns person identity, communication endpoints, and future communication preferences.
- Marketing owns lists, campaigns, approvals, sending state, tracking, suppressions, consent
  policy, interest tags, and sales follow-up signals.

## Current Slice

The implemented foundation now covers:

- Module routes, controller, and hub view.
- Permission catalog entries.
- Sales workspace navigation entry.
- ADR and Knowledge documentation.
- Email `marketing` sender/template scope, branded preview, and seeded campaign template.
- Marketing list tables for consent categories, interest tags, lists, and resolved members.
- Mailing list UI under `/tech/marketing/lists`.
- Mailing list segmentation by shared Contact and Client tags.
- Default consent categories and interest tags.
- Recipient resolution from active Contacts and legacy `client_users`.
- Campaign tables for drafts, ordered campaign emails, recipient queue, and tracking events.
- Marketing interest assignment aggregation for categorized campaign engagement on Contacts and
  Clients.
- Campaign UI under `/tech/marketing/campaigns`.
- Approval gate, due-recipient send job, scheduled minute processing, and `marketing:send-due`.
- Campaign sequence UI for multiple campaign emails with order, delay, scheduled time, subject
  override, pending-recipient due-time updates, and safe removal/deactivation.
- Open, click, and unsubscribe tracking endpoints.
- Admin settings UI for consent policy, unsubscribe behavior, active-contract eligibility, tracking
  defaults, quiet hours, and default send batching.

The hub intentionally exposes only implemented actions. WordPress pull, Google integrations, social
publishing, and richer Marketing-owned engagement follow-up lists are follow-up feature slices under
the approved RFC.

## Mailing Lists

Mailing lists currently support the `all_business_contacts` audience. Lists can optionally segment
that audience by shared Contact tags and Client tags. Contact tag criteria only match first-class
Contacts. Client tag criteria match both first-class Contacts related to a tagged Client and legacy
`client_users` on a tagged Client while compatibility migration continues.

Resolution materializes members into `marketing_list_members` so future campaign sends can use a
stable recipient snapshot.

Recipients are eligible when they are active, have an email address, and are not marked
`do_not_email`. The default Marketing setting is opt-out, so existing business contacts are included
unless they have opted out. If the setting is changed to explicit opt-in, Contacts must have
`marketing_consent=true` to be included.

Legacy `client_users` with no linked Contact are included while the Contact migration continues.
Recipients are deduplicated by lowercased email address before list members are stored.

## Campaigns

Campaigns are created as drafts and must be approved by a technician with
`marketing.campaign.approve` before any recipients are sent. Approval materializes recipient queue
rows for the selected list and active campaign emails.

Sending uses the selected Email account, or the active Email account marked as default for the
`marketing` scope. The scheduled job runs every minute and sends due recipients up to the campaign
batch size. Operators can also run:

```bash
php artisan marketing:send-due
```

Open tracking uses a 1x1 public pixel route. Click tracking rewrites HTTP/HTTPS links through a
public redirect route. Clicks are classified against active Marketing interest tags when the URL or
campaign context clearly matches known subjects such as security, websites, or cloud. Matching
interest is stored in `marketing_interest_assignments` for the linked Contact and Client with an
event count, score, first event, and last event. The campaign detail page shows campaign-level
interest signal counts.

Unsubscribe links mark linked Contacts as `do_not_email` and clear marketing consent.

Marketing settings live under `/tech/admin/settings/marketing`. Quiet hours pause due sending
without failing recipients. Active-contract eligibility is applied during list refresh, and
unsubscribe footer text is appended to campaign email HTML and plaintext.

Campaigns can contain multiple ordered emails. Technicians with `marketing.campaign.edit` can add,
update, deactivate, or remove campaign emails from the campaign detail page. Updating delay or
scheduled time changes only pending recipients. Sent recipient history is kept; removing a campaign
email with sent recipients deactivates it and cancels pending recipients instead of deleting history.
Adding a new active email to an approved or active campaign queues recipients for the existing list
members.

## Approved RFC

See `docs/rfc/2026-06-09-marketing-domain-email-campaigns.md`.

## Ownership Rules

- Keep all Marketing routes in `app/Modules/Marketing/routes.php`.
- Keep controllers in `app/Modules/Marketing/Controllers`.
- Keep views in `app/Modules/Marketing/Views`.
- Do not duplicate Email template storage in Marketing. Campaign emails should reference Email
  templates with the `marketing` scope when that slice is implemented.
- Do not move Sales pipeline behavior into Marketing. Marketing may create or feed Sales follow-up
  signals after engagement is tracked.
