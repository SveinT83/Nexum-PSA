# Knowledge Browser/API Coverage Baseline

Status: Verified On Dev; isolated Main candidate verified; merge authorized; production unchanged.
Date: 2026-10-27. Owner: Codex.
Baseline: 37 browser rows; 20 API rows / 24 HTTP operations (HEAD excluded).
Final: all 37 rows plus embedded workflows; 44 typed HTTP operations / 38 schemas.
Partial means an existing endpoint still needs complete actor/contract/behavior verification.
Missing means no current official API operation was found for that browser workflow.
Every final row must link explicit scopes, typed OpenAPI and successful/negative tests.

| ID | Browser route | Kind | Official API | Baseline gap (now verified) |
| --- | --- | --- | --- | --- |
| K01 | customer-portal.knowledge.index | read | GET knowledge/portal/articles | Missing |
| K02 | customer-portal.knowledge.show | read | GET knowledge/portal/articles/{article} | Missing |
| K03 | tech.admin.settings.knowledge | read | GET knowledge/settings | Missing |
| K04 | tech.admin.settings.knowledge.update | write | PUT knowledge/settings | Missing |
| K05 | tech.knowledge.index | read | GET knowledge/articles; GET knowledge/shelves; GET knowledge/context | Partial |
| K06 | tech.knowledge.book | read | GET knowledge/books/{book} | Partial |
| K07 | tech.knowledge.books.update | write | PUT/PATCH knowledge/books/{book} | Partial |
| K08 | tech.knowledge.books.destroy | write | DELETE knowledge/books/{book} | Partial |
| K09 | tech.knowledge.chapters.store | write | POST knowledge/chapters | Partial |
| K10 | tech.knowledge.chapters.create | read | GET knowledge/context; GET knowledge/lookups | Partial |
| K11 | tech.knowledge.books.edit | read | GET knowledge/context; GET knowledge/lookups; GET knowledge/books/{book} | Partial |
| K12 | tech.knowledge.books.pages.create | read | GET knowledge/context; GET knowledge/lookups; POST knowledge/articles | Partial |
| K13 | tech.knowledge.chapters.update | write | PUT/PATCH knowledge/chapters/{chapter} | Partial |
| K14 | tech.knowledge.chapters.destroy | write | DELETE knowledge/chapters/{chapter} | Partial |
| K15 | tech.knowledge.chapters.edit | read | GET knowledge/context; GET knowledge/lookups; GET knowledge/chapters/{chapter} | Partial |
| K16 | tech.knowledge.create | read | GET knowledge/context; GET knowledge/lookups | Partial |
| K17 | tech.knowledge.destroy | write | DELETE knowledge/articles/{article} | Partial |
| K18 | tech.knowledge.edit | read | GET knowledge/context; GET knowledge/lookups; GET knowledge/articles/{article} | Partial |
| K19 | tech.knowledge.revisions.show | read | GET knowledge/revisions/{revision} | Missing |
| K20 | tech.knowledge.revisions.approve | action | POST knowledge/revisions/{revision}/approve | Missing |
| K21 | tech.knowledge.revisions.publish | action | POST knowledge/revisions/{revision}/publish | Missing |
| K22 | tech.knowledge.revisions.reject | action | POST knowledge/revisions/{revision}/reject | Missing |
| K23 | tech.knowledge.revisions.retry | action | POST knowledge/revisions/{revision}/retry | Missing |
| K24 | tech.knowledge.revisions.rollback | action | POST knowledge/revisions/{revision}/rollback | Missing |
| K25 | tech.knowledge.shelves.store | write | POST knowledge/shelves | Partial |
| K26 | tech.knowledge.shelves.create | read | GET knowledge/context; GET knowledge/lookups | Partial |
| K27 | tech.knowledge.shelf | read | GET knowledge/shelves/{shelf} | Partial |
| K28 | tech.knowledge.shelves.update | write | PUT/PATCH knowledge/shelves/{shelf} | Partial |
| K29 | tech.knowledge.shelves.destroy | write | DELETE knowledge/shelves/{shelf} | Partial |
| K30 | tech.knowledge.books.store | write | POST knowledge/books | Partial |
| K31 | tech.knowledge.books.create | read | GET knowledge/context; GET knowledge/lookups | Partial |
| K32 | tech.knowledge.shelves.edit | read | GET knowledge/context; GET knowledge/lookups; GET knowledge/shelves/{shelf} | Partial |
| K33 | tech.knowledge.show | read | GET knowledge/articles/{article}; GET knowledge/articles/{article}/revisions | Partial |
| K34 | tech.knowledge.store | write | POST knowledge/articles | Partial |
| K35 | tech.knowledge.update | write | PUT/PATCH knowledge/articles/{article} | Partial |
| K36 | tech.knowledge.book-stack.accept-remote | action | POST knowledge/articles/{article}/book-stack/accept-remote | Missing |
| K37 | tech.knowledge.book-stack.keep-nexum | action | POST knowledge/articles/{article}/book-stack/keep-nexum | Missing |

## Embedded Workflows

- Livewire ArticleForm selection/reset/save: Context/lookups/validated article proposal; parent-child consistency.
- Taxonomy TagManager attach/remove/create: Knowledge-scoped tag discovery/create/attach/remove; module pivot.
- Library Sync Now button (Integration-owned): Explicit provider pull action; current integration rights and sanitized exact result.

## Dependencies And Boundaries
The Sync Now button is included even though its route is Integration-owned. The tag manager
is included even though it uses shared Livewire/Taxonomy code. Browser form/navigation reads
map to stateless context, lookups and exact detail rather than HTML API endpoints.
No role/team audience or future Documentation revision API is claimed (#265/#261/#275).
Documentation's own 30-row matrix is already verified and merged in PR #329.

## Final Verification

All mapped rows and the three embedded surfaces are implemented and verified on Dev.
All 44 actual successful HTTP methods match Swagger; see knowledge-api-verification.md/.json
for exact test batches, native/HTTPS/handbook evidence and independent review/deployment gates.
