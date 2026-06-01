# Knowledge Module

The Knowledge module owns the internal knowledge base workflow for tdPSA. It lets technicians create, edit, publish, review, archive, tag, and read knowledge articles.

This module follows the tdPSA module architecture rules:

- Routes live in `app/Modules/Knowledge/routes.php`.
- Controllers live in `app/Modules/Knowledge/Controllers`.
- Views live in `app/Modules/Knowledge/Views`.
- Livewire components live in `app/Modules/Knowledge/Livewire`.
- Livewire views live in `app/Modules/Knowledge/Views/Livewire`.
- Business operations live in `app/Modules/Knowledge/Actions`.
- Read/query composition lives in `app/Modules/Knowledge/Queries`.
- Module tests live in `app/Modules/Knowledge/Tests`.

Do not add Knowledge routes to `routes/web.php`, `routes/tech.php`, or any new file under `routes/`. Do not move Knowledge views back to `resources/views`, and do not move Knowledge controllers back to `app/Http/Controllers`.

## Purpose

A knowledge article captures operational guidance for technicians and, later, client-scoped or public-facing documentation.

Knowledge is organized like BookStack:

- Shelves group related books.
- Books contain pages directly and can also contain chapters.
- Chapters group pages inside a book.
- Articles are the local page records.

An `Article` answers:

- What is the title and stable slug?
- What markdown source was written?
- What rendered HTML is displayed?
- Who owns the article?
- Who created or last updated it?
- What category and tags classify it?
- Is it internal, client-wide, or public?
- Is it draft, published, archived, or due for review?
- Which client is it scoped to, if any?
- When should the article be reviewed again?
- How often has it been viewed?

## Product Direction

Knowledge is the internal knowledge system for tdPSA and should evolve toward a BookStack-compatible
information model without becoming a thin BookStack clone. The goal is to support PSA-native
ownership, review workflows, client context, tags, and permissions while being able to synchronize
with BookStack when a customer already uses it.

BookStack synchronization belongs to the Integration module, but Knowledge owns the local content
model that synced data lands in. Future Knowledge records should be able to distinguish locally
authored content from externally synced content by storing source system, external ID, source URL,
checksum, sync status, and last synced timestamp.

## Repository Documentation Sync

Module documentation stored under `app/Modules/*/Docs/knowledge` is synchronized into Knowledge with:

```bash
php artisan knowledge:sync-docs
```

Use `--module=Ticket` to limit the sync to one module. Use `--push` to queue the existing BookStack
push worker after the Knowledge records are updated. This is the normal publishing flow for
code-owned Knowledge pages; database seeders are not the day-to-day documentation publishing
mechanism.

AI chat will depend on Knowledge as one of its primary context sources:

- Page-context chat should retrieve relevant Knowledge articles for the current route, record,
  client, ticket, asset, category, or visible page metadata.
- Global chat should be able to search Knowledge more broadly while respecting user permissions.
- Synced BookStack content should be searchable and retrievable through the same Knowledge-facing
  context interface as local articles.
- Context extraction should return structured article/source references so AI answers can be traced
  back to the underlying documentation.

This means BookStack sync and Knowledge source metadata should be completed before building the full
AI chat experience.

## Directory Map

```text
app/Modules/Knowledge/
    Actions/
        DeleteArticle.php
        RecordArticleView.php
        RenderArticleBody.php
        StoreArticle.php
        UpdateArticle.php
    Controllers/
        Tech/
            KnowledgeController.php
    Livewire/
        ArticleForm.php
    Queries/
        ArticleQuery.php
    Tests/
        Feature/
        Unit/
    Views/
        Livewire/
            article-form.blade.php
        Tech/
            form.blade.php
            index.blade.php
            show.blade.php
    routes.php
    README.md
```

## Route Surface

All routes are registered in `app/Modules/Knowledge/routes.php`. They are loaded inside the existing authenticated Tech route group, so the final route names are prefixed with `tech.`.

| Method | URI | Route name | Purpose |
| --- | --- | --- | --- |
| GET | `/tech/knowledge` | `tech.knowledge.index` | List articles |
| GET | `/tech/knowledge/create` | `tech.knowledge.create` | Show create form |
| POST | `/tech/knowledge/store` | `tech.knowledge.store` | Create article fallback route |
| GET | `/tech/knowledge/show/{article}` | `tech.knowledge.show` | Show article and record view |
| GET | `/tech/knowledge/edit/{article}` | `tech.knowledge.edit` | Show edit form |
| PUT | `/tech/knowledge/update/{article}` | `tech.knowledge.update` | Update article fallback route |
| DELETE | `/tech/knowledge/destroy/{article}` | `tech.knowledge.destroy` | Soft-delete article |

The visible UI uses the Livewire form for create/edit. The POST and PUT routes remain as non-Livewire fallbacks and are useful for tests or progressive enhancement.

## Data Model

The module uses these Knowledge models:

```text
app/Models/Knowledge/Shelf.php
app/Models/Knowledge/Book.php
app/Models/Knowledge/Chapter.php
app/Models/Knowledge/Article.php
```

The library structure migrations are:

```text
database/migrations/2026_05_14_173945_create_knowledge_shelves_table.php
database/migrations/2026_05_14_173958_create_knowledge_books_table.php
database/migrations/2026_05_14_173958_create_knowledge_chapters_table.php
database/migrations/2026_05_14_174010_add_knowledge_structure_to_articles_table.php
database/migrations/2026_04_07_192649_create_articles_table.php
```

Important fields:

- `title`: article title.
- `slug`: unique slug generated from title plus a random suffix.
- `body_markdown`: editable source content.
- `body_html`: rendered HTML generated from markdown.
- `visibility`: `internal`, `client-wide`, or `public`.
- `status`: `draft`, `published`, `archived`, or `needs_review`.
- `owner_id`: user responsible for the article.
- `category_id`: optional category.
- `client_scope_id`: optional client scope when visibility is client-wide.
- `view_count`: simple article view counter.
- `next_review_at`: review due date.
- `created_by` / `updated_by`: audit metadata.
- `source_system`, `source_type`, `source_id`: external source identity for synchronized content.
- `source_url`: link back to the source document.
- `source_checksum`: source content fingerprint used to skip unchanged sync records.
- `source_synced_at` / `source_updated_at`: sync timing and upstream update timing.
- `sync_status`: current sync state, such as `synced`.
- `source_payload`: source metadata retained for debugging and future hierarchy mapping.

Relationships:

- `category()`: belongs to `App\Modules\Taxonomy\Models\Category`.
- `owner()`: belongs to `App\Models\Core\User`.
- `clientScope()`: belongs to `App\Models\Clients\Client`.
- `creator()`: belongs to `App\Models\Core\User`.
- `updater()`: belongs to `App\Models\Core\User`.
- `tags()`: morph-to-many relation through the shared `taggables` table.

## Workflow

### Article Listing

`ArticleQuery::paginateForTechIndex()` loads articles for the index page. It eager-loads `category` and `owner` because those fields are shown in the table.

Likely future filters belong in `ArticleQuery`:

- status
- visibility
- category
- owner
- client scope
- review due date
- search term

### Article Creation

1. The user opens `tech.knowledge.create`.
2. `KnowledgeController::create()` passes an unsaved `Article` to `knowledge::Tech.form`.
3. The form renders `<livewire:knowledge.article-form />`.
4. `ArticleForm::mount()` applies defaults:
   - `status = published`
   - `visibility = internal`
   - `next_review_at = one year from today`
5. `ArticleForm::save()` validates the input.
6. `StoreArticle` creates the article, assigns owner/creator, generates a unique slug, renders markdown to HTML, and saves the record.
7. The user is redirected to `tech.knowledge.show`.

### Article Editing

1. The user opens `tech.knowledge.edit`.
2. `KnowledgeController::edit()` passes the existing article to the same form view.
3. `ArticleForm::mount()` hydrates form fields from the existing article.
4. `ArticleForm::save()` validates the input.
5. `UpdateArticle` fills changed fields, sets `updated_by`, regenerates slug only if the title changed, re-renders markdown, and saves.

### Markdown Rendering

`RenderArticleBody` is the single rendering action.

Current behavior:

- If Laravel's `Str::markdown()` helper exists, it is used.
- Otherwise the markdown is escaped and line breaks are preserved.

This keeps rendering safe even if the framework version or Markdown helper changes. If article rendering becomes more advanced, replace this action with a dedicated Markdown pipeline and keep callers unchanged.

### Viewing Articles

`KnowledgeController::show()` eager-loads article metadata and calls `RecordArticleView`.

The current view counter is intentionally simple: every page view increments `view_count`. Future analytics can replace `RecordArticleView` without changing the controller route.

### Deleting Articles

`DeleteArticle` calls `$article->delete()`. The model uses SoftDeletes, so delete means soft-delete. A restore workflow can be added later.

## Livewire Registration

The component class lives inside the module:

```text
app/Modules/Knowledge/Livewire/ArticleForm.php
```

The Blade view lives inside the module:

```text
app/Modules/Knowledge/Views/Livewire/article-form.blade.php
```

`AppServiceProvider` registers the stable Livewire alias:

```php
Livewire::component('knowledge.article-form', App\Modules\Knowledge\Livewire\ArticleForm::class);
```

This allows module views to keep using:

```blade
<livewire:knowledge.article-form :article="$article" />
```

## View Namespaces

Module views are available through the lower-case module namespace:

```php
view('knowledge::Tech.index')
view('knowledge::Tech.form')
view('knowledge::Tech.show')
view('knowledge::Livewire.article-form')
```

The namespace is registered by `AppServiceProvider`, which scans `app/Modules/*/Views`.

## Authorization

General access is currently handled by the surrounding authenticated Tech route group and `tech` middleware.

The module does not yet have dedicated policies. Existing specification notes mention future permissions such as:

- `knowledge.view.tech`
- `knowledge.create`
- `knowledge.edit`
- `knowledge.delete`
- `knowledge.admin`
- `knowledge.manage.taxonomy`

If the authorization surface grows, add a policy for `Article` and move UI visibility checks plus controller enforcement there.

## Tags

The create/edit/show pages render the shared tag manager:

```blade
<livewire:system.tag-manager :model="$article" module="knowledge" />
```

The Article model's `tags()` relation uses the shared polymorphic `taggables` table and stores the module name on the pivot. This keeps Knowledge taxonomy aligned with other modules.

## Tests

Module tests should live in:

```text
app/Modules/Knowledge/Tests/Feature
app/Modules/Knowledge/Tests/Unit
```

Run module tests with:

```bash
php artisan test --testsuite=Modules --filter=Knowledge
```

Current local environment note: this workspace's PHP runtime does not have the SQLite PDO driver enabled, so database-backed Feature tests fail before application code runs with:

```text
could not find driver (Connection: sqlite)
```

Install/enable `pdo_sqlite` or configure the testing database to use an available driver before relying on Feature test results.

## Extension Points

Likely future work:

- Add Article policy and permissions.
- Add full-text search and filters to `ArticleQuery`.
- Add article restore workflow for soft-deleted records.
- Add article version history.
- Add review reminders based on `next_review_at`.
- Add publish/unpublish actions instead of raw status edits.
- Add client portal read access for `client-wide` articles.
- Add public read routes for `public` articles if tdPSA exposes external knowledge content.
- Add attachment support.
- Add richer Markdown rendering with sanitization and code highlighting.
- Add audit events for create/update/delete/view/tag changes.

## Developer Notes

- Keep controllers thin.
- Put business mutations in `Actions`.
- Put list/read composition in `Queries`.
- Keep all Knowledge routes in `app/Modules/Knowledge/routes.php`.
- Keep all Knowledge views in `app/Modules/Knowledge/Views`.
- Keep Livewire classes and Livewire views inside this module.
- Keep the `knowledge.article-form` alias stable unless all Blade usages are updated.
