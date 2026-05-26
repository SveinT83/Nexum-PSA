# tdPSA UI Guidelines

Updated: 2026-05-14

These guidelines define the intended user interface direction for tdPSA. They apply to all UI work in module Blade views, shared components, layout files, and page-specific CSS.

tdPSA should feel like an operational PSA workspace, not a generic admin panel. The interface should help technicians, admins, and service staff work quickly for long sessions with dense, scannable information and clear actions.

## Core Direction

The UI should move from "admin dashboard" toward "operational workspace".

Prioritize:

- Compact working surfaces.
- High information density.
- Fast scanning and comparison.
- Predictable action placement.
- Clear status, urgency, ownership, and next-step signals.
- Layouts that support the active workflow on the page.

Avoid:

- Marketing-dashboard spacing.
- Large decorative headers.
- Empty side panels.
- Wide unused gray space around narrow content.
- Inconsistent button sizes, colors, and action labels.
- Page sections that exist only to look balanced but do not help the workflow.

## Layout Shell

The main tech layout is `resources/views/layouts/default_tech.blade.php`.

Use the three-panel structure intentionally:

- Left panel: navigation context, filters, saved views, queue selectors, or page-specific scope controls.
- Center panel: the primary work surface.
- Right panel: operational context, warnings, stats, SLA, recent activity, related records, and later AI or knowledge suggestions.

Do not treat side panels as decoration. If a page has no useful side-panel content yet, keep it minimal and leave room for future workflow value.

Use more horizontal space for the primary work surface. Avoid designs where a large gray background surrounds a narrow centered content column unless the page is a focused form that genuinely benefits from that constraint.

## Header And Navigation

The top bar should be short and utilitarian.

Guidelines:

- Keep the header height low to preserve vertical working space.
- Avoid large logos, tall padding, and stacked header content.
- Keep breadcrumbs compact.
- Page headers should state the current object or work queue, then expose the most important actions.
- Do not duplicate large title areas inside the page body when the layout header already provides page context.

## Page Header

The page header must adapt to the content it actually contains. It should not reserve the same vertical space on every page.

Guidelines:

- Page headers are compact work-surface labels, not banners.
- The default shape is one low line: current page or object name on the left, and at most one or two primary navigation/actions on the right.
- Do not put explanatory subtitle text in PageHeader. Text such as "Shelves, books, chapters, and pages" belongs in body content, cards, empty states, or documentation, never in the page header.
- Use the centralized breadcrumb system in `config/breadcrumbs.php` and `resources/views/partials/breadcrumbs.blade.php`. Do not write per-view breadcrumb markup in Blade views.
- Detail views should define dynamic breadcrumb labels through the shared breadcrumb resolver instead of rendering local breadcrumb HTML.
- Keep page-header padding tight by default.
- Reduce vertical padding further when the header has no buttons, tabs, or breadcrumbs.
- Do not leave empty rows or placeholder space for actions that are not present.
- Breadcrumbs should not force a tall header; when breadcrumbs are absent or short, the header should collapse naturally.
- If a page only needs a title, the page header should feel like a compact label for the work surface, not a banner.
- Put section-specific actions in the relevant card header or panel. For example, an asset `Edit` action belongs in `Asset Details`, and RMM sync actions belong in an integration/sync panel rather than the global page header.
- Page-header navigation must use the shared button components, especially `resources/views/components/buttons/back.blade.php` for Back. Do not hand-roll Back links or override the component classes in a way that removes the standard icon/button treatment.
- Avoid explanatory subtitles in the page header. If context is needed, place it in the first body card or a compact help surface.

## Density And Spacing

PSA users spend long sessions in this system. Dense does not mean cramped; it means every pixel should help the workflow.

Guidelines:

- Use tighter card padding, table padding, and form spacing than a marketing dashboard.
- Prefer compact rows for operational lists such as tickets, clients, assets, services, and SLA policies.
- Keep repeated cards at `8px` border radius or less unless the existing component requires otherwise.
- Avoid large empty vertical gaps between filters, actions, tables, and detail panels.
- Avoid oversized headings inside panels, cards, sidebars, and table-adjacent sections.

## Tables And Lists

Tables and lists should support rapid scanning.

Guidelines:

- Put identifiers, status, priority, owner, customer, SLA, and updated time where users can scan them without opening each record.
- When each row represents one primary object, prefer making the row itself open the detail page instead of repeating an `Open` button on every row.
- Row-click navigation must still be accessible: keep a real link on the primary identifier/name, provide visible hover/focus states, and avoid hiding keyboard navigation.
- Reserve row action buttons for secondary or destructive actions. Do not add an `Open` button unless row-click navigation would be unclear or unsafe.
- Keep row actions predictable and visually quiet.
- Prefer icon buttons or a single `Actions` dropdown when multiple row actions exist.
- Avoid mixing full text buttons, icon-only buttons, and differently colored buttons in the same row unless there is a clear risk or destructive action.
- Use destructive styling only for destructive actions.
- Keep table controls close to the table they affect.
- Avoid vague placeholders such as `N/A` for operational values. Use a clearer state like `Not assessed`, `No score`, `Unknown`, or a muted dash depending on what the data actually means.
- Missing values should not look like real data. Use muted styling for absent, unknown, or not-yet-calculated values.
- Operational index lists should sit in a card or similarly clear bounded list surface when that improves scanability. Put search and filters directly above the list, not inside the global page header.
- For dense operational indexes, prefer a search card with the main search field visible and secondary filters hidden behind a compact funnel-icon toggle. Open the filter area automatically when secondary filters are active and show a small active-filter count badge on the funnel button.
- When a create action belongs specifically to the list being searched or filtered, place that action on the same row as the search/filter controls. Keep only navigation-level actions such as `Back` in the page header.
- Sortable table headers should be real links with compact sort indicators. Preserve current filters/search in sort links.
- Detail/list rows may be clickable when the row has one natural destination. Keep the primary name/identifier as a real link for accessibility and stop row-click handling on nested links.
- Use a muted dash (`—`) as the default display for absent short values such as address, city, phone, and email unless a more specific state is operationally useful.

## Cards And Panels

Cards should frame meaningful repeated records or focused tools. Page sections should not all become floating cards.

Guidelines:

- Use cards for individual items, summary widgets, modals, and genuinely grouped forms.
- Use a compact details card or panel when a small set of metadata fields belongs together, such as name, description, ownership, or configuration summary.
- Summary cards on detail pages should use the available width. Prefer two- or three-column info grids for read-only metadata when there is enough horizontal space.
- Do not put cards inside cards.
- Do not use card-heavy layouts for simple operational pages.
- Repeated operational groups should be sibling cards or panels, not wrapped in another parent card. For example, priority-specific SLA response blocks can be three cards in one row without an outer "Response Time" card.
- Section labels should describe the domain concept, not the field type. Prefer names like "Policy Details", "Ticket Details", or "Customer Details" over generic labels such as "Descriptions".
- Use full-width bands or unframed layouts for major page sections.
- Keep card headers small and action-oriented.
- Card headers should earn their space. When data is available, use the header for compact stats, status icons, counts, warnings, filters, or primary actions instead of leaving it visually empty.
- For related-record cards, such as assets on a client page, expose useful summary signals in the header: total count, monitored versus unmonitored, active warnings, stale records, or other workflow-relevant indicators.
- Use small icons with accessible labels/tooltips for dense header signals, but keep the text or tooltip clear enough that users do not have to open records just to understand the state.
- Avoid headers that only repeat the card title if the body already makes the content obvious and no action or summary context is present.

## Admin And Settings Hubs

Admin landing pages should be calm settings hubs rather than action-heavy dashboards.

Guidelines:

- Use classic Bootstrap cards with a compact `card-header` for each settings group.
- Put the group icon and title in the card header so the card itself remains the primary visual object.
- Keep action buttons visually quieter than the card. Prefer neutral Bootstrap outline buttons over full-width filled action rows.
- Use a responsive card grid, commonly four columns on wide screens and fewer columns on smaller screens.
- Inside each card, prefer a compact two-column action grid for settings links instead of vertical `ul` / `li` lists or large row buttons.
- Keep custom CSS minimal and limited to small alignment, sizing, and density refinements that Bootstrap does not cover cleanly.
- Admin settings pages should use the shared Admin sidebar menu. The sidebar should include a common `Admin areas` section that navigates between the Admin landing-page card groups, followed by a local section for the current card group's own settings links.
- Do not hand-roll per-view admin sidebars with raw `ul` / `li` markup. Add or adjust entries in the shared Admin menu component when Admin navigation changes.

## Read-Only Views

Show/detail pages should not automatically reuse disabled edit forms.

Guidelines:

- Prefer compact read-only detail panels for show pages.
- Present read-only metadata as label/value pairs in a two- or three-column info grid when the content is mostly short fields.
- Use disabled form controls only when the page is explicitly an inline-edit surface or when keeping form layout is necessary for a short transition period.
- Keep the primary edit action in the page header or a consistent actions area.
- Read-only pages should make important values easier to scan than they would be in an edit form.
- Avoid single-column read-only summaries when they leave large unused horizontal space.

## Form Layouts

Operational create/edit forms should stay readable when they contain many fields.

Guidelines:

- Keep one HTML form for one save action, but split long forms into sibling cards with focused headers such as `Task`, `Context`, `Workflow & Schedule`, `Classification`, and `Checklist`.
- Do not nest cards inside a form card. Use sibling cards inside the same `<form>` so the save action remains simple while the page is easier to scan.
- Put the primary submit action at the bottom of the form, not in every card.
- Use dynamic searchable inputs for large reference lists such as clients, sites, tickets, vendors, and suppliers. Avoid long static select boxes when users normally know what they are searching for.
- Search suggestions should use standard Bootstrap dropdown/list styling such as `dropdown-menu` and `dropdown-item`. Keep suggestions hidden until the user types, then hide them again after selection so forms do not become noisy.
- Dependent fields should narrow each other. Selecting a site may set the client; selecting a ticket may set client, site, estimated time, and ticket billing rate context.
- Checklist-style form inputs should use add/remove row controls for normal users. Plain newline textareas are acceptable only for import/paste-heavy power-user workflows.

## Actions

Actions must be consistent across modules.

Standard patterns:

- Primary page action: one clear primary button near the page title or table header.
- Create/add actions should use the shared add button/link components from `resources/views/components/buttons` unless the page has a specific reason not to.
- Action buttons should include the established icon treatment when a shared component already provides one.
- When passing spacing classes such as `mb-0` to shared button components, append to the standard component style rather than replacing it.
- Secondary actions: neutral buttons or dropdown actions.
- Row actions: icon buttons or one compact dropdown.
- Destructive actions: visually distinct and preferably confirmed.

Avoid action rows like `Open`, `Edit`, `Delete`, `Edit Services` with different sizes and mixed styling. Normalize them into a predictable pattern before adding more actions.

## Badges And Status

Badges are important in tdPSA and should stay compact.

Guidelines:

- Use badges for status, priority, unread state, assignment state, SLA risk, and lifecycle.
- Keep badge padding tight and height low.
- Prefer subtle background colors with readable text over heavy saturated blocks.
- Use consistent colors for the same concept across modules.
- Do not use a badge when plain text is easier to scan.

## Ticket UI Direction

Ticket screens are the benchmark for the whole system's operational feel.

Ticket UI should emphasize:

- Queue and ownership.
- Customer, contact, site, and asset context.
- Priority, category, tags, and SLA.
- Unread state and latest activity.
- Conversation timeline.
- Internal notes versus customer-visible replies.
- Assignment decisions and warnings.
- Fast reply, assign, close, and mark-read workflows.

The ticket interface should feel tighter and more information-rich than settings pages. It should support the question: "What does the technician need to do next?"

Ticket show pages should show the original request text in a subject-titled card directly above the conversation timeline. Keep operational ticket metadata in the right sidebar when that sidebar already provides the established details surface.

Conversation timelines can use accordion rows to keep history scannable. Read messages should default collapsed, while unread customer/contact messages should default expanded so the next action is visible immediately.

Unread conversation replies should expose a compact per-reply `Mark as read` action inside the expanded row. Clearing one reply should not hide or clear other unread replies on the same ticket.

Conversation row headers should prioritize participant context and a short message excerpt over multiple badges. Keep the header to one compact line on desktop: message type, unread state when relevant, participant, excerpt, and timestamp.

Message composers on ticket show pages can be collapsed by default when the main workflow is reading triage context. Keep them expanded after validation errors or preserved input so technicians do not lose their place.

When the composer is collapsed, expose a compact quick action near the original request or conversation context. A reply shortcut should open the composer, select the appropriate message type, scroll to it, and focus the message body.

## Side Panels

Side panels should carry workflow value.

Useful right-panel content includes:

- SLA status and due times.
- Recent activity.
- Related tickets.
- Customer warnings.
- Open assets or alerts.
- Assignment state and owner workload.
- Knowledge suggestions.
- Quick actions.

Useful left-panel content includes:

- Queue filters.
- Saved views.
- Lifecycle filters.
- Ownership filters.
- Client/site scoping.
- Category and priority filters.

Avoid placeholder-only text such as "Overview" unless it is temporary during active development.

Rightbar cards should be compact by default. Most rightbar sections should be implemented as accordions and collapsed by default, especially when they contain secondary details, raw metadata, documentation, sync tools, related records, or long operational context. Keep only the highest-signal, next-action content expanded by default, such as active SLA risk, unread/customer activity, critical warnings, or currently failing checks.

Rightbar accordion headers should expose enough signal to scan without opening the section: title, count, status badge, warning icon, or last-updated timestamp where relevant. Avoid empty card headers that only repeat a title and force users to expand every section to know whether it matters.

Documentation widgets in rightbars should open focused help content in a Bootstrap modal when the user is staying in the same workflow. External/new-tab links are acceptable only as secondary links to raw Markdown or full Knowledge pages.

## Taxonomy Inputs

Global tags should behave like a classic tag system on create/edit forms: users type into a text input, existing tags are suggested while typing, Enter/comma creates a chip, and unknown tag names are created on save. Avoid multi-select tag boxes for operational forms unless the list is intentionally small and fixed.

## Sidebar Navigation

Sidebar navigation must be easy to scan and have enough contrast for long work sessions.

The Sales workspace menu in `resources/views/components/nav/sales-menu.blade.php` is the visual reference for workspace sidebars. New and updated workspace menus should follow that pattern unless a page has a specific workflow reason to do something different:

- Use a compact uppercase workspace label at the top, such as `Sales workspace` or `Client workspace`.
- Render menu items as vertical Bootstrap `nav-pills` with `gap-1`.
- Use `px-3 py-2` item padding for a dense but tappable target.
- Use Bootstrap Icons before the text label on primary menu items.
- Style inactive links as `link-dark bg-light border`.
- Use Bootstrap's `active` nav state and `aria-current="page"` for the current route.
- Keep labels short and operational; avoid explanatory text inside the navigation itself.
- Put help or concept notes behind a small icon tooltip when the label needs context, such as client documentation modeled after Passportal.

Guidelines:

- Do not use low-contrast text on light gray backgrounds for sidebar menus.
- Sidebar menu items should look and behave like compact navigation buttons, not loose text links.
- Use Bootstrap Icons in sidebar menu buttons where an icon can make the destination faster to recognize.
- Keep icons consistent in size, alignment, and spacing.
- Show active, hover, and focus states clearly.
- Use text labels together with icons for primary sidebar navigation; icon-only navigation is allowed only when the icon is universally clear and has an accessible label or tooltip.
- Avoid decorative icons that do not help recognition or scanning.
- When a top-level navigation group, such as Sales, spans multiple modules or views, use one shared sidebar component for that workspace instead of duplicating page-specific menu markup.
- Cross-module workspaces, such as Sales, Work, and Knowledge, should keep module ownership in each module while sharing one global workspace sidebar component.
- Include the workspace sidebar across all views that belong to that navigation group unless the page has a stronger workflow-specific sidebar.

## Footer

The tech workspace should not reserve visible vertical space for a generic footer.

Prefer:

- No visible footer in the operational shell.
- A future compact sticky status/info bar only if it provides operational value.

## Forms

Forms should be clear, compact, and predictable.

Guidelines:

- Group fields by workflow meaning, not by visual symmetry.
- Put required operational fields first.
- Use visual hierarchy inside forms so the most important or most frequently used fields stand out from secondary metadata.
- Not every field group should look equally important. Primary fields, required fields, and workflow-driving fields should have stronger placement or clearer grouping than optional details.
- Keep labels and help text concise.
- Avoid oversized form cards for simple create/edit screens.
- Reuse shared form components from `resources/views/components/forms` where practical.
- For show/read-only pages, avoid making disabled forms look like the primary detail presentation unless editing in place is part of the workflow.

## Visual Style

tdPSA should feel modern, restrained, and work-focused.

Guidelines:

- Use Bootstrap consistently, then layer shared components and light custom CSS where needed.
- Keep contrast consistent between panels, cards, tables, and badges.
- Avoid one-off colors per page.
- Avoid decorative gradients, oversized hero sections, and illustrative dashboard decoration.
- Keep typography compact and readable.
- Do not scale font sizes with viewport width.

## Implementation Rules

When changing UI:

- Read this file before making broad UI changes.
- Reuse shared Blade components from `resources/views/components` before creating new markup.
- Improve shared components when the same pattern appears in multiple modules.
- Keep module views inside `app/Modules/{Domain}/Views`.
- Keep page-specific UI decisions close to the module, but move repeated patterns into shared components.
- Use visible Blade section comments for major layout areas.
- Add clear English comments only where they explain structure, intent, or non-obvious behavior.

## Review Checklist

Before considering UI work done, check:

- Does the page support the user's active workflow?
- Is the header compact enough?
- Is the main work surface using enough width?
- Are cards, tables, and forms dense enough for operational use?
- Are row actions consistent?
- Are badges compact and meaningful?
- Do side panels contain useful operational context?
- Is there unnecessary footer or decorative space?
- Does the page look like part of the same PSA system as the rest of tdPSA?
