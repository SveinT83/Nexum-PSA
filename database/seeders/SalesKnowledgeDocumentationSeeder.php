<?php

namespace Database\Seeders;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Actions\RenderArticleBody;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Stores the current Sales domain documentation in Knowledge.
 *
 * The seeder is intentionally idempotent so the BookStack-ready documentation can
 * be refreshed when the Sales implementation changes.
 */
class SalesKnowledgeDocumentationSeeder extends Seeder
{
    public function run(RenderArticleBody $renderer): void
    {
        $book = Book::query()->firstOrCreate(
            ['slug' => 'bookstack-book-nexum-psa-339'],
            [
                'name' => 'Nexum PSA',
                'description' => 'Nexum PSA product documentation.',
                'priority' => 100,
                'source_system' => 'nexum',
                'source_type' => 'product-docs',
                'sync_status' => 'pending',
            ],
        );

        $chapter = Chapter::query()->updateOrCreate(
            [
                'book_id' => $book->id,
                'slug' => 'sales',
            ],
            [
                'name' => 'Sales',
                'description' => 'Sales opportunities, quotes, follow-up, and sales pipeline behavior.',
                'priority' => 700,
                'source_system' => 'nexum',
                'source_type' => 'sales-docs',
                'sync_status' => 'pending',
            ],
        );

        $userId = User::query()->value('id');

        foreach ($this->articles() as $index => $article) {
            $markdown = trim($article['body']);

            Article::query()->updateOrCreate(
                [
                    'source_system' => 'nexum',
                    'source_type' => 'sales-docs',
                    'source_id' => $article['slug'],
                ],
                [
                    'title' => $article['title'],
                    'slug' => $article['slug'],
                    'body_markdown' => $markdown,
                    'body_html' => $renderer->handle($markdown),
                    'visibility' => 'internal',
                    'status' => 'published',
                    'owner_id' => $userId,
                    'knowledge_book_id' => $book->id,
                    'knowledge_chapter_id' => $chapter->id,
                    'priority' => ($index + 1) * 10,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                    'source_checksum' => sha1($markdown),
                    'source_updated_at' => now(),
                    'sync_status' => 'pending',
                    'source_payload' => [
                        'module' => 'Sales',
                        'generated_from' => static::class,
                    ],
                ],
            );
        }
    }

    /**
     * Knowledge pages for the first complete Sales vertical slice.
     */
    private function articles(): array
    {
        return [
            [
                'title' => 'Sales Overview',
                'slug' => Str::slug('Sales Overview'),
                'body' => <<<'MARKDOWN'
Sales owns the commercial sales process before delivery work starts. The module uses its own opportunity records instead of storing active sales work as tickets.

Core model:

- Client is the company/account record used across Nexum.
- Lead candidate is a client without an active contract.
- Sales opportunity is an active sales process for a client.
- Quote is the commercial proposal attached to an opportunity.
- Ticket is only created later for onboarding, delivery, or support work after a sale is won.

Implemented behavior:

- `/tech/sales` lists active opportunities, pipeline value, expected close dates, and quote status.
- `/tech/sales/leads` lists clients without active contracts and lets the seller filter, sort, group, classify, tag, and start a sales process through a modal.
- Lead classification uses sales category, lead heat, website data, and shared Taxonomy tags on the Client record so later campaign segmentation can reuse the same data. Tags are edited as chips from a typeahead input; existing tags are suggested while typing, and new tag names are created on save. Traits such as missing website should be tracked as tags or categories when they need sorting or campaign selection.
- `/tech/sales/create` creates an opportunity for existing clients, including owner, type, probability, estimates, follow-up date, and next action.
- New Opportunity includes a quick client modal for prospects that are not registered yet. It uses the same client number, organization number, client format, and default site/contact creation logic as the normal Client form, then returns to the opportunity form with the new client selected.
- Admins manage client formats from Clients Settings. Default English formats are Limited Company (`AS`), Sole Proprietorship (`ENK`), and Private Individual (`PRIVATE`), and custom formats such as Startup or Nonprofit can be added later.
- New Opportunity lets the seller choose a primary sales contact or create a new contact inline for the selected client.
- Opportunity pages show forecast data, editable sales fields, quote lines, activity timeline, and journal entries.
- Opportunity edit can change the primary sales contact or create a new contact when discovery identifies the right decision maker.
- Inbound prospect replies and public quote questions are marked unread until the seller marks the activity or opportunity as read.
- The Sales list sorts unread opportunities first before normal follow-up priority.
- Follow-up dates can be synced to the owner's work calendar.

Default opportunity types:

- Service agreement
- Equipment sale
- Project
- Renewal
- Upsell / additional service
- Other

Default statuses:

- New lead
- Contacted
- Discovery
- Quote ready
- Quote sent
- Negotiation
- Won
- Lost
- Follow up later

The first implementation uses default statuses and settings. A future workflow editor should allow different Sales flows by opportunity type.
MARKDOWN,
            ],
            [
                'title' => 'Sales Quotes',
                'slug' => Str::slug('Sales Quotes'),
                'body' => <<<'MARKDOWN'
Quotes are versioned snapshots of what was offered to the customer. A quote version stores prices, quantities, discount, VAT, margin, intro text, terms, and closing text.

Supported line sources:

- Custom line
- Service
- Service package
- Time rate
- Storage item

Quote line behavior:

- Unit cost and unit price are stored without VAT.
- Discount can be set per line.
- VAT is stored per line when applicable.
- The quote version calculates subtotal, discount, VAT, total including VAT, estimated cost, margin amount, and margin percentage.
- Sent and accepted quote versions should be treated as audit snapshots.

Opportunity page behavior:

- The quote card shows status, line count, and totals by default.
- Quote details are collapsed by default.
- `Prepare Quote` is only shown before the first quote draft exists.
- Draft quotes can be edited from the `Edit Quote` modal.
- Draft quote lines use a searchable catalog field for services, packages, time rates, and storage items. Selecting a catalog record fills the hidden source ID and preloads name, description, price, cost, and VAT where available.
- Sent or accepted quotes are read-only from the opportunity page.

Public quote portal:

- Each quote has a public token.
- The customer can view the quote from the public link.
- The customer can download or print the PDF.
- The customer can accept the quote.
- The customer can ask a question before accepting.

Email behavior:

- Sending a quote marks the quote version as sent and queues `SendSalesQuoteEmail`.
- Quote email delivery uses the active `sales_quote_send` Email template.
- Customer-visible activity email uses the active `sales_activity_email` Email template.
- Internal note notifications use the active `sales_internal_note` Email template.
- Inbound replies are linked back to Sales by reply headers or the `SO-YYYY-XXXXXX` opportunity key.

Acceptance behavior:

- Public acceptance marks the quote version as accepted.
- The parent quote is marked accepted.
- The opportunity is moved to `won`.
- A `quote_accepted` activity entry is created.

Question behavior:

- Public quote questions are stored as inbound Sales activity.
- If the opportunity is still open, the status moves to negotiation.

Important limitation in this implementation:

- Quote email requires a primary contact with a valid email address. Without that, the quote is still marked sent and the public link is shown to the seller.
- Accepted quotes do not yet automatically generate contracts, orders, or onboarding tickets. The status hooks are in place so those flows can be added next.
MARKDOWN,
            ],
            [
                'title' => 'Sales Calendar And Follow-Up',
                'slug' => Str::slug('Sales Calendar And Follow-Up'),
                'body' => <<<'MARKDOWN'
Sales opportunities can create calendar follow-up events for the opportunity owner.

Follow-up fields:

- `next_follow_up_at` stores when the seller should act next.
- `next_follow_up_type` stores the next action as a controlled select value, for example call, meeting, email, quote follow-up, discovery, demo, or proposal review.
- `expected_close_date` stores forecast timing.
- `sales_calendar_event_id` links the opportunity to the generated calendar event.

Calendar behavior:

- When follow-up calendar creation is enabled, a follow-up date creates or updates a personal work calendar event.
- The event is assigned to the opportunity owner.
- The event title includes the opportunity title.
- The event body includes the client, opportunity key, status, and need summary.

Settings:

- `create_calendar_followups` controls whether follow-up events are created.
- `default_followup_duration_minutes` controls the event duration.
- `quote_expiry_calendar_reminder_days` is reserved for quote expiry reminders.

Operational rule:

Sellers should use `next_follow_up_at` for promised callbacks, quote expiry follow-up, discovery meetings, and negotiated "contact again later" dates. The calendar is the reminder surface; the opportunity remains the commercial source of truth.
MARKDOWN,
            ],
            [
                'title' => 'Sales Settings And Permissions',
                'slug' => Str::slug('Sales Settings And Permissions'),
                'body' => <<<'MARKDOWN'
Sales defaults are created by `EnsureSalesDefaults`.

Permissions:

- `sales.view`
- `sales.create`
- `sales.edit`
- `sales.manage_quotes`
- `sales.admin`

Default role assignment:

- Admin and Superuser receive all Sales permissions.
- Tech receives view, create, edit, and quote management access.

Settings:

- `quote_expiry_days`: default quote validity period.
- `create_calendar_followups`: create calendar events from opportunity follow-up dates.
- `quote_expiry_calendar_reminder_days`: reserved for quote expiry reminders.
- `default_followup_duration_minutes`: duration for generated follow-up events.
- `auto_create_onboarding_ticket`: reserved for won-sale onboarding automation.
- `require_seller_instructions_for_onboarding`: reserved for onboarding ticket creation rules.

Future settings should control:

- Which workflow is used per opportunity type.
- Whether accepted quotes generate contracts automatically or require review.
- Whether accepted quotes generate Economy orders.
- Whether won opportunities create onboarding tickets automatically or manually.
- Which email account is used for quote sending.
- Which quote template is used by default.
MARKDOWN,
            ],
            [
                'title' => 'Sales Future Work',
                'slug' => Str::slug('Sales Future Work'),
                'body' => <<<'MARKDOWN'
The current implementation is a practical first Sales vertical slice. The following work should be prioritized after the base UI has been tested with real opportunities.

Near-term work:

- Add selectable quote/email template variants per client, language, brand, or opportunity type.
- Add stakeholder/contact editing directly in the opportunity UI.
- Generate onboarding tickets when a won opportunity requires delivery work.
- Generate contracts from accepted service quotes.
- Generate Economy order drafts from accepted quotes where appropriate.
- After beta, add email marketing segmentation and engagement scoring using client sales categories, tags, link clicks, and time spent on public quote/landing pages.

Workflow work:

- Add configurable Sales workflows similar to Ticket workflows.
- Allow workflow rules to control quote sending, won/lost actions, onboarding creation, and required fields.
- Support different flows per opportunity type.

Forecast and AI work:

- Use AI to help estimate probability based on tone, replies, activity, company data, and objections.
- Add public company enrichment for lead quality, risk, and conversation hints.
- Add sales suggestion logic for existing clients that may need renewals, projects, services, or equipment.

Documentation rule:

When Sales behavior changes, update both `app/Modules/Sales/Views/Tech/Sales/sales.md` and these Knowledge pages so BookStack stays aligned with the implemented system.
MARKDOWN,
            ],
        ];
    }
}
