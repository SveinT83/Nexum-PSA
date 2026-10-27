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
- Mailing list editing plus manual canonical Contact additions/removals from Contacts with active
  email addresses, and guarded deletion for lists that are not used by campaigns.
- Campaign draft, approval, recipient queue, due send job, and open/click/unsubscribe tracking
  foundation.
- Campaign sequence editing for multiple ordered campaign emails as collapsible cards with
  campaign-level send rhythm, optional extra delay, editable email subject/body snapshots, preview,
  test-send, new-contact policy, recipient batch throttling, and active-campaign recipient queue
  sync.
- Evergreen contact progression that keeps campaigns active and idle when current contacts are
  caught up, then enrolls new contacts or advances caught-up contacts when another email is added.
- Lifetime delivery identity guards and an atomic SMTP claim that prevent automatic replay of the
  same campaign-email record to the same contact or mailbox.
- WordPress content pull on campaign detail pages. Pulled posts are cached as campaign content
  sources and included as grounded AI context for campaign planning and email drafting.
- Suppression hardening for list resolution, unsubscribe, and final pre-SMTP send checks. Suppressed
  recipients are marked `suppressed` and are not sent.
- AI-assisted campaign planning from campaign, list, available template, and current sequence
  context, including cached WordPress content sources, when an active AI agent is available.
- AI-assisted campaign email drafting from campaign, list, and current email context when an active
  AI agent is available.
- Dashboard summaries for campaigns, recipients, mailing lists, sender setup, templates, queue, and
  tracking activity.
- Admin settings for consent, unsubscribe, active-contract eligibility, tracking defaults, quiet
  hours, and send batching.
- Sanctum API endpoints for mailing lists, list members, campaigns, campaign emails, approval,
  due-send queueing, AI assists, and Marketing settings.
- Sales Leads consumes Marketing events and interest assignments as engagement context on the
  existing Leads screen. Marketing does not create seller call lists.

The Email foundation slices add the `marketing` sender scope, `marketing` Email template scope,
brand-aware HTML rendering, template preview, and a seeded default marketing campaign template.

Google integrations, social publishing, and the shared drag/drop HTML content editor remain
separate follow-up slices. Separate Marketing engagement/call lists are intentionally out of scope;
marketing interest is consumed by Sales/Leads context, Lead Heat, and classification.

## Mailing Lists

Technicians with `marketing.list.manage` can create and edit lists from `/tech/marketing/lists`. The
current audiences are `all_business_contacts` and `manual_contacts`. The all-business audience can
be segmented by shared Contact and Client tags, client industry category, contract status, postcode,
county, and country. Location criteria match Contact address, retained compatibility data, and
Client Site fields. Either audience can include manually selected canonical Contacts. Saved legacy
Client User selections remain readable and are automatically paired with canonical Contact IDs
during the production cutover.

List resolution includes:

- Active Contacts with at least one email address.
- Contacts that are not marked `do_not_email`.
- Contacts with `marketing_consent=true` when Marketing settings require explicit opt-in.
- Canonical Contacts linked to retained `client_users` compatibility evidence, deduplicated as one person.
- Selected Contact tags, when the list has Contact tag criteria.
- Selected Client tags, when the list has Client tag criteria.
- Selected Client industry categories, contract status, postcode, county, and country.
- Selected manual Contacts, when the list has manual Contact criteria.
- Retained manual legacy Client User criteria, resolved together with their canonical Contact IDs.
- Contacts removed from this specific list are excluded from future refreshes until they are added
  again.
- Suppression entries by email, domain, Contact, or Client. Suppressed recipients are not
  materialized from automatic, manual, or Lead Intelligence list sources.

List membership is materialized in `marketing_list_members`. This gives campaigns a stable recipient
snapshot and lets operators refresh the list when Contacts change. Duplicate email addresses are
collapsed before members are stored. Refresh rebuilds criteria-driven members, but preserves members
promoted by Lead Intelligence unless the Contact has been removed from that specific list.

The list detail page lets list managers add eligible existing Contacts and remove resolved
first-class Contacts. Removal is a Marketing list exclusion, not a Contact preference change; it does
not set `do_not_email` or change global consent.

The edit page lets list managers delete unused mailing lists. Deletion removes the list and its
resolved members, but never deletes Contacts. Lists used by campaigns are protected from deletion
whether they are the legacy primary list or one of the selected campaign audience lists, so campaign
sequence, recipient, and tracking history is preserved.

Contact tag criteria only match first-class Contacts. Client tag, industry, contract, and location
criteria match Contacts related to matching Clients and legacy `client_users` that belong to
matching Clients.

Manual Contact criteria resolve canonical Contacts. Historical manual Client User criteria are
retained for compatibility, while the Contact cutover adds matching `manual_contact_ids` and keeps
both identities on materialized members. Eligibility still excludes opted-out, inactive, suppressed,
or email-less Contacts even when an ID remains in saved criteria.

Default consent categories are seeded on Marketing page access: Newsletter, Security, Websites, and
Cloud. Default interest tags prepare later open/click tracking and Sales categorization.

## Canonical Contact Cutover

The forward-only Contact migration automatically enriches existing Marketing data when legacy Client
contacts are copied to canonical Contact:

- `marketing_list_members` keep `client_user_id` and receive the matching `contact_id`;
- saved `manual_client_user_ids` remain intact and matching `manual_contact_ids` are added;
- existing campaign recipients receive canonical Contact identity;
- campaign events receive the Contact identity of their recipient;
- sent or claimed deliveries receive additional Contact, Client User, and normalized-email identity
  keys on the same durable delivery.

This enrichment is additive and idempotent. It never creates a new recipient, queues a due send,
calls SMTP, or replays a campaign email. If canonical identity would point to a different durable
delivery, Marketing blocks the recipient for review and the deployment migration stops instead of
weakening the lifetime no-resend invariant.

Legacy Client User IDs remain present because older recipient history and other domains may still
refer to them. They are compatibility evidence, not a second user-facing contact catalogue.

## Campaigns

Marketing campaigns are created as drafts from one or more mailing lists, send rhythm, sender
account, and send preferences. Campaign emails are added after the campaign exists from the campaign detail page.
Each campaign email uses an active Email template with the `marketing` scope as its starting point,
then stores its own subject, HTML body, plaintext body, materialized outer layout, and template
metadata as a snapshot. The body uses the shared visual/source HTML editor; layout ownership stays
with the reusable Email template and is not edited from the campaign. Each campaign can choose a
sender account. If it does not, Marketing uses the active Email account marked
as default for the `marketing` scope.

A campaign must be approved by a technician with `marketing.campaign.approve` before sending. On
approval, Marketing creates the next missing `marketing_campaign_recipients` step for each current
eligible member of every selected audience list. It does not queue every later email in advance.
Recipients are matched by Contact, legacy `client_users` identity, and normalized email address.
Durable delivery keys guard both person identity and mailbox identity across list refreshes,
overlapping lists, email casing, and historical cycles. The queue stores due time, status, attempts,
message id, tracking token, and its delivery-ledger link.
Draft and paused campaigns expose a deduplicated audience recipient count from their selected
lists, while actual recipient queue rows remain approval-only.

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
subscribers do not receive old campaign emails. Under the default start-first policy, a contact added
later receives email 1 at the next configured campaign occurrence. Each later step is created only
after the prior applicable step is confirmed sent, and uses the next calendar-aligned occurrence.

Sent and claimed history is kept. If a sent sequence email is removed, the email is deactivated and
pending recipients are cancelled; unsent sequence emails are deleted with their pending queue rows.
After every current contact is caught up, the campaign remains active but idle. There is no automatic
repeat cycle. A newly appended active email becomes the next missing step for caught-up and newer
contacts without replaying an earlier campaign-email record. A legacy `completed` campaign stays
inert until explicitly continued or extended, at which point the same lifetime no-resend rule applies.

Campaign emails send from their stored snapshot, not from the live Email template. This means an
administrator can later change a reusable Marketing template without silently changing draft,
approved, active, or historical campaign emails that were already created from it. Company Profile
branding is materialized into that layout snapshot at campaign-email creation for the same reason.

Adding a sequence email to an approved or active campaign creates the next eligible pending step for
current list members at their next configured occurrence. Contacts that are still progressing reach
the appended email in order; caught-up contacts receive it as their next step. The technician surface
shows ongoing sequence behavior and derived in-progress, caught-up, blocked/review, and next-due
information instead of completion and repeat controls.

Preview uses the editable visual/source HTML body and the campaign's stored layout. It calls the same
server renderer as outbound delivery instead of building a second browser-only layout. Known
recipient/company placeholders such as `contact_name`, `client_name`, `company_name`, and
`unsubscribe_url` are rendered with real campaign/audience-list context when available, then clear
sample values. Unknown placeholders stay visible so operators can see that the system does not know
what data should replace them. Test-send uses the current editor fields and sends through the campaign sender
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
Campaign detail pages can pull recent WordPress posts from a site URL or WordPress REST posts URL.
Marketing stores the post title, excerpt, content HTML, source URL, published date, and fetched time
as `marketing_content_sources`. Campaign planning and email drafting include those cached sources
as grounded `wordpress_content`. Other external website URLs in prompts are still treated as
destination links or brand hints unless they have been pulled and stored as content sources.

The scheduled Marketing job runs every minute and sends due recipients in batches. Before SMTP, the
job re-checks Contact opt-out, explicit consent mode, suppression entries, sender account, and
provider binding. It then acquires one durable delivery claim and reserves a stable RFC Message-ID.
Only the worker holding that claim may transmit. Suppressed recipients are marked `suppressed`,
logged as `MARKETING_EMAIL_SUPPRESSED`, and skipped. An exception after provider writing begins is
marked as an unknown outcome, blocks progression, and is never retried automatically. The manual
command is:

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

Unsubscribe records the event, creates or updates a Marketing suppression entry, and marks linked
Contacts as `do_not_email` with `marketing_consent=false`.

Marketing campaign events also write normalized records to the Signal domain. Signal rules can tag
Contacts or Clients, suppress marketing email, emit derived signals, or call webhooks. Marketing
lists remain the owner of audience selection and can later use those tags during refresh.

## Deployment And Operations

Deploy the evergreen delivery guard in this order:

1. Run the read-only preflight and review only its sanitized counts:

   ```bash
   php artisan marketing:delivery-preflight
   ```

2. Create and integrity-check an approved application/database backup.
3. Run `php artisan migrate --force`.
4. Run `php artisan optimize:clear`.
5. Run `php artisan queue:restart` and verify the default-queue workers restarted from the deployed
   project.
6. Repeat the read-only preflight/readback checks and verify an external once-per-minute
   `schedule:run` trigger or supervised `schedule:work` process.

Never roll back or remove the delivery ledger or identity-key tables after a runtime claim exists.
They are the durable lifetime no-resend evidence; preserve them and use a reviewed forward repair.

Authoritative Dev currently has three active `email,default` workers and Marketing uses the default
queue. The read-only failed-job count found zero Marketing failures and two unrelated failures; no
payload or failure detail was exposed. No tdPSA scheduler runner was found in accessible cron,
systemd, or process sources, and the root crontab was unavailable. Queue processing is therefore
verified, while automatic scheduled dispatch remains unverified.

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

## API

Marketing API routes are available under `/api/v1/marketing` for integrations with Sanctum tokens.
The Client and Contact APIs remain responsible for creating or updating Clients and Contacts.
Contact API writes can set `do_not_email` and `marketing_consent`, and those fields are respected
when Marketing lists resolve members.

The Marketing API scopes are:

- `marketing.read`
- `marketing.lists.manage`
- `marketing.campaigns.create`
- `marketing.campaigns.update`
- `marketing.campaigns.approve`
- `marketing.campaigns.send`
- `marketing.settings.update`

The API exposes list create/read/update/delete, member lookup, list refresh, Contact add/remove,
campaign create/read/update, schedule update, campaign email create/update/delete/test-send, AI plan,
AI email draft, approval, due-send queueing, and settings read/update. These endpoints reuse the same
Marketing actions as the technician UI, so recipient resolution, campaign email snapshots, approval,
queue creation, and due-send behavior stay consistent. Repeat lifecycle values are rejected on write.
Campaign resources identify the ongoing contact-sequence mode and expose old completion, repeat, and
cycle fields only as deprecated compatibility data.

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


## Full API Workflow Coverage (2026-10-27)

Availability: implemented on Dev under the approved 2026-10-27 RFC. Main promotion is authorized and under verification. Production has not been deployed or activated. Do not describe these additions as available in production until separately verified.

## Prerequisites And Access

Use an active internal Nexum user with the native Marketing permission for the operation.
Open **Admin -> Integrations -> API Management** to issue a narrowly scoped bearer token;
review the requested abilities against the user's actual permissions. A token never grants
native permissions. Keep tokens in protected secret storage and send them only in the
Authorization header over trusted HTTPS. Do not paste tokens or sender credentials into chat.

Existing scopes are marketing.read, marketing.lists.manage, marketing.campaigns.create,
marketing.campaigns.update, marketing.campaigns.approve, marketing.campaigns.send and
marketing.settings.update. New scopes are marketing.context.read, marketing.providers.execute
and marketing.operations.read. Settings reads/updates also require the native
marketing.settings.manage permission; marketing.read alone is insufficient.

Form context requires both marketing.context.read and the matching existing action scope
and native permission. List context/Contact choices require marketing.list.manage and
marketing.lists.manage. Campaign creation/edit context requires the corresponding create/edit
permission and create/update scope. This supports narrow operators without global read access.
No API GET seeds permissions or activates an AI agent/provider. Initial/default configuration
remains an explicit administration/setup task.

## Work Through An Audience And Campaign

1. In the web app use **Marketing -> Lists** and **Marketing -> Campaigns**; the same
   supported data operations are available through the endpoint table below.
2. Read the appropriate list/campaign context, then page through `/marketing/lookups/{kind}`.
   Supported kinds: contacts, legacy-contacts, tags, industries, consent-categories, lists,
   accounts and templates. Each result is bounded (per_page 1-100, default 25). Search with q.
   Use list_id with contact choices to omit existing members. Context includes selected labels,
   schedule options, safe sender/template fields and provider availability without credentials.
3. Create a mailing list using the supported automatic or manual audience and exact criteria.
   Add/remove Contacts or refresh through the list actions. List removal is an exclusion for
   that list, not a global unsubscribe. Campaign-used lists cannot be deleted.
4. Create the draft campaign with one or more list IDs. Read it back before adding ordered
   email steps from active Marketing Email templates. Campaign emails keep content/layout
   snapshots; later template changes do not silently rewrite the sequence.
5. Preview unsaved content with `emails/preview`; supply campaign_email_id or an active
   email_template_id and optional editor overrides. The authoritative renderer returns
   subject/html/text without saving, queueing, SMTP or tracking events. Foreign campaign
   email IDs are rejected. Render HTML in a sandboxed preview, as the web editor does.
6. Read `actions/preview` before approval/send work. Re-read the campaign for its ETag,
   then approve using the dedicated scope/native right. Approval is not delivery.
7. Use the schedule operation to set calendar rhythm and batch spacing. Read paginated
   recipients, events and cached content through their exact campaign paths. The compatibility
   include_recipients expansion is capped at 100; use the queue endpoint for the full result.

List resolution and final sending retain consent, suppression and opt-out checks. Multi-list
recipients are deduplicated. Each contact advances through each campaign-email identity once;
a caught-up campaign waits for new Contacts or newly appended emails. Repeat/resend fields
remain prohibited. Claimed or uncertain sends require review and are not automatically replayed.

## Commands, Revisions And Read-Back

1. Use a fresh Idempotency-Key (8-200 visible ASCII characters) for a distinct mutation.
2. For state-dependent keyed changes, send If-Match with the exact ETag from current
   list/campaign/settings read-back. The same key with the same method/path/payload and
   original If-Match replays its saved result. A different payload/key identity conflicts.
3. Read X-Operation-Id / operation_id, then GET `/marketing/operations/{operation}` using
   marketing.operations.read plus the original action scope/native right and the same token.
   The result is encrypted at rest and available only to its current actor/token authority.
4. After 412 reload and assess the intervening change, then submit a new deliberate command.
   After 409 do not repurpose a used key. Deleted/foreign objects may return 404; the own
   operation endpoint remains the read-back path. Revoked actors/tokens lose access.

Legacy unkeyed mutations remain accepted for compatibility and return a Warning header.
They do not have the safe retry guarantee of a stable command key. New WordPress pull requires
a key. Unknown top-level fields and invalid pagination are rejected rather than ignored.
Browser and API local mutations serialize through the same native DB mutex and refresh bound
state before writes; stale input cannot silently overwrite current keyed state.

## AI, WordPress And Sending

AI plan/draft, WordPress pull, SMTP test and API due-send require both the normal operation
scope/native right and marketing.providers.execute. The separate
MARKETING_API_PROVIDER_EXECUTION_ENABLED switch is false by default. This API switch does not
activate providers or change existing web/scheduler Marketing behavior. A configured agent,
Email provider and valid current bindings are still required. Do not enable it as part of
ordinary token provisioning or troubleshooting a 409 without approved operating intent.

AI responses are editable suggestions and do not save/approve campaigns. WordPress pull reads
public HTTPS content and caches source titles/snippets/links; it does not publish anything.
The URL must have no embedded credentials/custom port and resolve only to public IPs. Each
redirect is validated/pinned and certificate checks stay enabled. Private/internal sources
are intentionally rejected. Use the cached-source endpoint to verify exactly what was stored.

Test-send returns SMTP/provider acceptance evidence and message ID, not inbox receipt.
Due-send returns a queued job intent, not a sent/received message. API queued work retains its
origin and rechecks current actor/token scope, execution switch and sender binding. Existing
web/scheduler execution remains under its existing boundary. A running/unknown command requires
reconciliation; the same key must not trigger another external attempt. Never reset an unknown
SMTP outcome to pending merely to retry. Read retained recipient delivery/claim state instead.

For scheduled operation, queue workers and the external every-minute Laravel scheduler runner
are separate requirements. This change does not start workers, change the schedule or enable
a provider. Inbox receipt is a separate verification claim from local/provider state.

## API Operations

All paths below are relative to `/api/v1`. Public recipient tracking/unsubscribe links keep
their existing token URLs and image/redirect/text responses; do not replace them with bearer
administration URLs or expose tracking tokens in API results.

| Methods | Path | Ability | Native permission |
| --- | --- | --- | --- |
| GET | `/marketing/lists` | `marketing.read` | marketing.view |
| POST | `/marketing/lists` | `marketing.lists.manage` | marketing.list.manage |
| GET | `/marketing/lists/{list}` | `marketing.read` | marketing.view |
| PUT, PATCH | `/marketing/lists/{list}` | `marketing.lists.manage` | marketing.list.manage |
| DELETE | `/marketing/lists/{list}` | `marketing.lists.manage` | marketing.list.manage |
| GET | `/marketing/lists/{list}/members` | `marketing.read` | marketing.view |
| POST | `/marketing/lists/{list}/refresh` | `marketing.lists.manage` | marketing.list.manage |
| POST | `/marketing/lists/{list}/contacts` | `marketing.lists.manage` | marketing.list.manage |
| DELETE | `/marketing/lists/{list}/contacts/{contact}` | `marketing.lists.manage` | marketing.list.manage |
| GET | `/marketing/campaigns` | `marketing.read` | marketing.view |
| POST | `/marketing/campaigns` | `marketing.campaigns.create` | marketing.campaign.create |
| GET | `/marketing/campaigns/{campaign}` | `marketing.read` | marketing.view |
| PUT, PATCH | `/marketing/campaigns/{campaign}` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/ai-plan` | `marketing.campaigns.update` | marketing.campaign.edit |
| PUT, PATCH | `/marketing/campaigns/{campaign}/schedule` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/emails` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/emails/ai-draft` | `marketing.campaigns.update` | marketing.campaign.edit |
| PUT, PATCH | `/marketing/campaigns/{campaign}/emails/{email}` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/emails/{email}/test-send` | `marketing.campaigns.update` | marketing.campaign.edit |
| DELETE | `/marketing/campaigns/{campaign}/emails/{email}` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/approve` | `marketing.campaigns.approve` | marketing.campaign.approve |
| POST | `/marketing/campaigns/{campaign}/send-due` | `marketing.campaigns.send` | marketing.campaign.send |
| GET | `/marketing/settings` | `marketing.read` | marketing.settings.manage |
| PUT, PATCH | `/marketing/settings` | `marketing.settings.update` | marketing.settings.manage |
| GET | `/marketing/dashboard` | `marketing.read` | marketing.view |
| GET | `/marketing/context/lists` | `marketing.context.read` | marketing.list.manage |
| GET | `/marketing/lists/{list}/context` | `marketing.context.read` | marketing.list.manage |
| GET | `/marketing/context/campaigns` | `marketing.context.read` | marketing.campaign.create |
| GET | `/marketing/campaigns/{campaign}/context` | `marketing.context.read` | marketing.campaign.edit |
| GET | `/marketing/lookups/{kind}` | `marketing.context.read` |  |
| GET | `/marketing/campaigns/{campaign}/recipients` | `marketing.read` | marketing.view |
| GET | `/marketing/campaigns/{campaign}/content-sources` | `marketing.read` | marketing.view |
| GET | `/marketing/campaigns/{campaign}/events` | `marketing.read` | marketing.view |
| POST | `/marketing/campaigns/{campaign}/emails/preview` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/content-sources/wordpress` | `marketing.campaigns.update` | marketing.campaign.edit |
| POST | `/marketing/campaigns/{campaign}/actions/preview` | `marketing.campaigns.send` | marketing.campaign.send |
| GET | `/marketing/operations/{operation}` | `marketing.operations.read` |  |

## Troubleshooting

- 401: token expired/revoked or no supported authentication. Reissue only with proper authorization.
- 403: check the current native permission, matching operation scope and provider scope; do not grant a blanket role.
- 404: check exact parent/child IDs and actor/token-owned operation identity; do not search other campaigns to bypass it.
- 409: inspect command-key conflict or the disabled API execution switch before another attempt.
- 412/428: re-read the ETag and supply the required key/precondition deliberately.
- 422: inspect declared input fields/enums and known workflow validation; repeat fields are prohibited.
- 503: the exact additive Marketing migration is missing; do not run every pending migration in shared Dev.

Deployment needs `2026_10_27_060000_create_marketing_api_commands.php`, generated Swagger,
normal caches and worker restart if deployed job code changed. Preserve APP_KEY, command
receipts, immutable recipient/delivery identity evidence and existing scope/consent settings.
Human-review checklist HR-2026-10-27-MARKETING-API remains Pending and gates production/release.
Main merge authorization does not complete human scenarios or authorize production activation.
