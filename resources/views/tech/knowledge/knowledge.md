# Knowledge Module – Functional Specification

**Date:** 2025-10-16  
 **Scope:** Technician-facing Knowledge Base (internal first, optionally client/public)  
 **Primary URL namespace:** `tech.knowledge.*` (root: `/tech/knowledge`)  
 **Primary View(s):**

- `tech.knowledge.index` – article list with maintenance actions (already specced)
- `tech.knowledge.create` – create new article
- `tech.knowledge.show` – read view
- `tech.knowledge.edit` – edit article
- `tech.knowledge.settings` – categories/tags/publishing rules (under Admin if you prefer)

**Access (minimum):** `knowledge.view.tech` to read list; `knowledge.create|edit|delete` for authoring/maintenance (match the permissions style in your view map).  
 **Controller namespace:** `App\\Http\\Controllers\\Tech\\Knowledge\\*`  
 **Status:** Not completed  
 **Difficulty:** Medium  
 **Estimated time:** 6.0 hours

**Layout template (Bootstrap):**  
 Top header / Main content / Right slim rail (preview, metadata, quick actions)

---

## 1) Purpose & Positioning

- Provide a central repository of solutions, procedures, and runbooks used by technicians during ticket work.
- Tight integration with Tickets: show KB suggestions in ticket side panel and allow “Promote to KB draft” from a ticket.
- Serve multiple audiences via visibility controls (Internal only → Client-scoped → Public).
- Ensure versioning, review, and audit so content remains trustworthy over time (ties into platform’s Audit).

---

## 2) Core Object Model (conceptual)

**Article**

- id, title, slug
- body_markdown (stored in Markdown), body_html (cached render)
- excerpt (auto or manual)
- visibility: Internal | Client-wide | Public (default Internal; Public optional)
- status: Draft | Published | Archived | Needs Review
- owner (user)
- categories (1..n), tags (0..n)
- client_scope (nullable; required if visibility = Client-wide)
- linked_tickets (computed count; many-to-many)
- version: current_version_id; has many **ArticleVersion**
- review_policy: {review_interval_days, next_review_at}
- audit fields (created_by, updated_by, timestamps)

**ArticleVersion**

- article_id, version_number
- title, body_markdown snapshot
- created_by, created_at
- change_note

**Category**

- id, name, slug, sort_order (flat list, one level)

**Tag**

- id, name, slug (flat; created on use)

> Keep taxonomy lightweight: Categories = classification; Tags = ad-hoc discovery.

---

## 3) Editor (authoring)

**Markdown+ editor only**

- Markdown with fenced code blocks, tables, images, callouts/alerts, checklists
- Paste image → auto-upload attachment, insert reference
- Slash-menu for insertions (table, callout, hint, code, mermaid optional)
- Autosave and draft recovery
- Live preview (right slim rail)
- Templates/snippets (e.g., “How-to”, “Runbook”, “Known Issue”)
- AI assist (optional): summarize, improve clarity (logs to audit)
- Link validator and attachment manager

---

## 4) Visibility & Audience

- **Internal** (default): visible to technicians only (`knowledge.view.tech`).
- **Client-wide:** visible to authenticated users of a specific client (portal). Requires `client_scope`.
- **Public:** available externally if enabled in settings.

> Enforcement aligns with existing RBAC and portal scoping.

---

## 5) Publishing Workflow

**States**

- Draft → Published → Archived
- Draft ↔ Needs Review (content flagged or policy-due)

**Transitions (rules)**

- Publish requires: title, at least one category, visibility, owner.
- Archive allowed for `knowledge.delete` or `knowledge.edit` with archive rights.

**Review Queue**

- Each published article sets `next_review_at` (e.g., 180 days).
- Nightly job moves overdue items to “Needs Review”.
- Bulk “Extend review” action for maintainers.

**Approvals**

- No mandatory peer approval.
- Optional reminder widget for overdue reviews.

All actions write audit entries (who/what/when, before→after).

---

## 6) Taxonomy

**Categories**

- Single-level list; used for navigation and suggestion context.

**Tags**

- Freetext; auto-created when new tag used.
- Displayed as chips; click to filter.

**Collections (optional)**

- Saved filters, e.g., “Onboarding Pack”, implemented as stored queries.

---

## 7) Integration with Tickets

- Ticket side panel shows relevant KB via rules/AI.
- “Promote to KB Draft” imports title, summary, and resolution fields (no full thread).
- Articles can manually link related tickets.
- Workflows can require KB link when workaround used.

---

## 8) Search, Filters & Sorting (index recap)

- Full-text on title/body/tags.
- Filter by visibility, status, category, tags, owner, client scope, date range, “has links”.
- Sort: Updated desc (default), Title, Owner, Status.
- Saved views per user.
- Live updates (no reload).

---

## 9) Right Slim Rail (preview & quick edit)

- Tabs: Preview | Meta | Linkage | Activity
- Meta quick edits: visibility, status, tags, owner, client scope (policy-checked)
- Displays audit snippets

---

## 10) Settings (module)

**Path:** `tech.knowledge.settings` or under Admin → Templates/Knowledge.

**Sub-areas:**

- Categories: CRUD with sort order.
- Tags: CRUD with dedupe.
- Publishing: default review interval, default visibility.
- Editor: Markdown+ configuration, templates.
- Exposure: toggle Public visibility.
- Retention: soft-delete retention days.

**Permissions (suggested):**

- `knowledge.admin` for overall control.
- `knowledge.manage.taxonomy` for category/tag management.

---

## 11) Roles & Permissions (minimum)

- View (tech): `knowledge.view.tech`
- Create: `knowledge.create`
- Edit: `knowledge.edit`
- Delete/Archive: `knowledge.delete`
- Admin/Settings: `knowledge.admin`

> Keep consistent with global permission taxonomy.

---

## 12) Components (Livewire)

- `FilterBar` – generic list filter
- `BulkToolbar` – generic bulk ops
- `KnowledgeTable` – virtualized list
- `PreviewPane` – right rail preview/meta
- `StatsStrip` – mini KPIs (Draft, Published, Internal, Public, Needs Review)

---

## 13) Icons (no colors)

Lucide: `file-text`, `book-open`, `tag`, `folder-tree`, `user`, `link-2`, `clock`, `refresh-ccw`, `search`, `filter`, `upload`, `check`, `eye`, `eye-off`, `history`, `git-branch`, `shield`.

---

## 14) Non-functional

- Performance: virtualized list, paginated queries, indexed search.
- Audit: all state changes recorded.
- Accessibility: keyboard navigation, semantic headings, alt text.
- PWA: toasts for publish/approval; badge for new content.
- Security: server-side enforcement of visibility and scoping.

---

## 15) QA Acceptance (high level)

- Create draft with required fields and publish.
- Visibility rules enforced across Internal/Client/Public.
- Review queue identifies overdue articles and supports bulk extension.
- Ticket → KB draft promotion carries metadata.
- Version history records and restores Markdown revisions.
- Audit entries present for publish, visibility, tag edits, archive/restore.