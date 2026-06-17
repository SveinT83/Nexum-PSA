# Marketing Domain

The Marketing domain owns campaign and audience workflows for Nexum PSA.

Marketing is separate from Sales and Email:

- Sales owns leads, opportunities, quotes, and sales activities.
- Email owns SMTP/IMAP accounts, outbound templates, rendering, and low-level delivery.
- Contact owns person identity, communication endpoints, and future communication preferences.
- Marketing owns lists, campaigns, approvals, sending state, tracking, suppressions, consent
  policy, and interest tags. Sales consumes marketing interest inside the Leads/Sales workflow.

## Current Slice

The implemented foundation now covers:

- Module routes, controller, and email marketing dashboard view.
- Permission catalog entries.
- Sales workspace navigation entry.
- ADR and Knowledge documentation.
- Email `marketing` sender/template scope, branded preview, and seeded campaign template.
- Email marketing dashboard summaries for campaigns, recipients, lists, tracking, templates, and
  sender setup.
- Marketing list tables for consent categories, interest tags, lists, and resolved members.
- Mailing list UI under `/tech/marketing/lists`.
- Mailing list audience modes for all business contacts or manually selected Contacts only.
- Mailing list segmentation by shared Contact and Client tags.
- Mailing list edit flow plus manual Contact additions/removals from existing Contacts with active
  email addresses, and guarded deletion for lists that are not used by campaigns.
- Default consent categories and interest tags.
- Recipient resolution from active Contacts and legacy `client_users`.
- Campaign tables for drafts, ordered campaign emails, recipient queue, and tracking events.
- Marketing interest assignment aggregation for categorized campaign engagement on Contacts and
  Clients.
- Campaign UI under `/tech/marketing/campaigns`.
- Approval gate, due-recipient send job, scheduled minute processing, and `marketing:send-due`.
- Campaign sequence UI for multiple campaign emails as collapsible cards with order, campaign-level
  send rhythm such as every Friday at 12:00, extra per-email delay, editable email subject/body
  snapshots, live preview, test-send, pending-recipient due-time updates, new-contact policy,
  recipient batch throttling, and safe removal/deactivation.
- AI-assisted campaign email drafting through active Integration AI agents. AI suggestions update
  the editable form only and must still be saved by the technician.
- Open, click, and unsubscribe tracking endpoints.
- Admin settings UI for consent policy, unsubscribe behavior, active-contract eligibility, tracking
  defaults, quiet hours, and default send batching.

The hub intentionally exposes only implemented actions. WordPress pull, Google integrations,
social publishing, and richer AI/content tooling are follow-up feature slices under the approved
RFC and should only become visible when the underlying integration or workflow exists. Marketing
must not grow a separate engagement/call-list workflow; marketing interest should feed Lead Heat
and classification in the Sales/Leads module.

## Mailing Lists

Mailing lists currently support the `all_business_contacts` and `manual_contacts` audiences. Lists
can optionally segment all business contacts by shared Contact tags and Client tags, and can also
include manually selected existing Contacts. Contact tag criteria only match first-class Contacts.
Client tag criteria match both first-class Contacts related to a tagged Client and legacy
`client_users` on a tagged Client while compatibility migration continues. Manual contacts are
resolved from first-class Contacts only.

Resolution materializes members into `marketing_list_members` so future campaign sends can use a
stable recipient snapshot. Refresh rebuilds criteria-driven members, but preserves members promoted
by Lead Intelligence unless the Contact has been removed from that specific list.

Technicians with `marketing.list.manage` can edit list details and criteria after creation. They can
also add eligible Contacts directly from the list detail page, or remove resolved first-class
Contacts from the list. Removed Contacts are stored as list-level exclusions and stay excluded on
future refreshes until they are added again. This does not mark the Contact as `do_not_email`; it
only changes membership for that Marketing list.

Unused mailing lists can be deleted from the edit screen. Deleting a list removes its resolved list
members but does not delete Contacts. Lists already used by campaigns are protected from deletion so
campaign sequence, recipient, and tracking history is not removed through the existing campaign
foreign-key cascade.

Recipients are eligible when they are active, have an email address, and are not marked
`do_not_email`. The default Marketing setting is opt-out, so existing business contacts are included
unless they have opted out. If the setting is changed to explicit opt-in, Contacts must have
`marketing_consent=true` to be included.

Manual contacts use the same eligibility rules as automatic segments. If a selected Contact later
opts out, is inactive, or loses its email address, list refresh keeps the manual criterion but does
not materialize that Contact as an eligible recipient.

Legacy `client_users` with no linked Contact are included while the Contact migration continues.
Recipients are deduplicated by lowercased email address before list members are stored.

## Campaigns

Campaigns are created as drafts from a mailing list, send rhythm, sender account, and send
preferences. Campaign emails are added from the campaign detail page after the campaign exists. A
campaign must be approved by a technician with `marketing.campaign.approve` before any recipients
are sent. Approval materializes recipient queue rows for the selected list and active campaign
emails.

Each campaign email starts from an active Email template with the `marketing` scope, then stores its
own subject, HTML body, plaintext body, source template name, and template variables as a snapshot.
Sending uses that campaign email snapshot, so changing the reusable Email template later does not
silently change draft, approved, active, or historical campaign emails.

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
unsubscribe footer text is appended to campaign email HTML and plaintext when the email content does
not already include `unsubscribe_url`.

Campaigns can contain multiple ordered emails. Technicians with `marketing.campaign.edit` can add,
update, deactivate, or remove campaign emails from the campaign detail page. Existing emails are
shown as cards with recipient, sent, open, and click counts, then expanded only when a technician
wants to preview, test-send, or edit them. New emails are created from a hidden form; selecting a
start template fills the editable snapshot fields before saving.

Campaign scheduling is owned at campaign level. Operators choose a send rhythm such as daily,
weekly, monthly, or a custom interval, plus first send date, send time, and for weekly campaigns the
weekday. For example, weekly with Friday and 12:00 schedules the ordered campaign emails on Fridays
at noon. Campaign email `delay_minutes` is only an optional extra delay for that specific sequence
step. Updating the campaign schedule recalculates pending recipient due times. On the campaign
detail page, schedule and recipient queue panels are collapsed by default so the ordered campaign
emails remain the primary work surface.

Recipient throttling is separate from sequence timing. `batch_size` controls how many recipients in
one campaign email are due at the same time, and `send_interval_minutes` spaces the next recipient
batch to reduce delivery bursts. New contacts can either start at the first campaign email, which is
useful for nurture sequences, or join the current campaign schedule, which is useful for newsletter
lists where new subscribers should not receive old news. Sent recipient history is kept; removing a
campaign email with sent recipients deactivates it and cancels pending recipients instead of
deleting history. Adding a new active email to an approved or active campaign queues recipients for
the existing list members according to the campaign schedule.

Campaign email preview is rendered in the browser from the editable HTML body. Test-send uses the
current editor fields and sends through the campaign sender account or the default `marketing`
account. The test recipient defaults to the current technician email address and can be overwritten.
AI is exposed as a compact icon on the campaign and email editor surfaces; the prompt opens only
when the technician expands that assist control. A seeded Marketing Campaign Agent is used when it
can be attached to an active Integration AI provider. Without an active provider/agent, the assist
controls stay visible but disabled inside the opened panel. AI uses campaign context, list context,
and current email content, then returns editable fields; it does not send, approve, or save the
campaign by itself. AI-generated marketing emails are expected to include `unsubscribe_url` in the
editable body so operators can see the unsubscribe footer in preview. External website fetching is
not implemented yet. URLs in prompts are treated as destination links or brand hints only until a
future content-source integration fetches and stores that content.

## Approved RFC

See `docs/rfc/2026-06-09-marketing-domain-email-campaigns.md`.

## Ownership Rules

- Keep all Marketing routes in `app/Modules/Marketing/routes.php`.
- Keep controllers in `app/Modules/Marketing/Controllers`.
- Keep views in `app/Modules/Marketing/Views`.
- Keep reusable template ownership in Email. Marketing campaign emails may reference the source
  Email template for traceability, but the actual campaign email content is owned by Marketing as a
  snapshot.
- Do not move Sales pipeline behavior into Marketing. Marketing may record engagement and interest
  signals, but Lead Heat, classification, seller sorting, and follow-up workflow belong in Sales.
