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
- 503: check the exact Marketing migration and infrastructure health, then read command state before retrying. Do not expose debug traces or run every pending migration in shared Dev.

Deployment needs `2026_10_27_060000_create_marketing_api_commands.php`, generated Swagger,
normal caches and worker restart if deployed job code changed. Preserve APP_KEY, command
receipts, immutable recipient/delivery identity evidence and existing scope/consent settings.
Human-review checklist HR-2026-10-27-MARKETING-API remains Pending and gates production/release.
Main merge authorization does not complete human scenarios or authorize production activation.
