<?php

use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use App\Modules\Knowledge\Controllers\Admin\KnowledgeSettingsController;
use App\Modules\Knowledge\Controllers\Portal\PortalKnowledgeController;
use App\Modules\Knowledge\Controllers\Tech\KnowledgeBookStackConflictController;
use App\Modules\Knowledge\Controllers\Tech\KnowledgeController;
use App\Modules\Knowledge\Controllers\Tech\KnowledgeRevisionController;
use Illuminate\Support\Facades\Route;

// Portal bearer and existing-session reads resolve the current caller's membership.
if (($knowledgePortalApiRoutes ?? false) === true) {
    Route::middleware(['web', 'auth:sanctum', \App\Modules\Knowledge\Support\PortalKnowledgeApiAccess::class, 'throttle:60,1'])
        ->group(function (): void {
            Route::get('knowledge/portal/articles', [\App\Modules\Knowledge\Controllers\Api\V1\PortalKnowledgeController::class, 'index'])->name('knowledge.portal.index');
            Route::get('knowledge/portal/articles/{article}', [\App\Modules\Knowledge\Controllers\Api\V1\PortalKnowledgeController::class, 'show'])->name('knowledge.portal.show');
        });

    return;
}

// Official Knowledge domain API; api.php is a compatibility loader only.
if (($knowledgeApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Knowledge\Support\KnowledgeApiAccess::class,
        \App\Modules\Knowledge\Actions\ExecuteKnowledgeApiCommand::class])->group(function (): void {
            Route::match(['get'], 'knowledge/shelves', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController::class, 'index'])
                ->name('knowledge.shelves.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', ['q', 'page', 'per_page', 'source_system', 'sync_status'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['post'], 'knowledge/shelves', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController::class, 'store'])
                ->name('knowledge.shelves.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
                ->defaults('knowledge_ability', 'knowledge.create')
                ->defaults('knowledge_permissions', ['knowledge.create'])
                ->defaults('knowledge_fields', ['name', 'description', 'sync_to_book_stack'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/shelves/{shelf}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController::class, 'show'])
                ->name('knowledge.shelves.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'shelf')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.update']);
            Route::match(['put', 'patch'], 'knowledge/shelves/{shelf}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController::class, 'update'])
                ->name('knowledge.shelves.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.update'])
                ->defaults('knowledge_fields', ['name', 'description', 'sync_to_book_stack'])
                ->defaults('knowledge_target', 'shelf')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['delete'], 'knowledge/shelves/{shelf}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeShelfController::class, 'destroy'])
                ->name('knowledge.shelves.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->missing(function (\Illuminate\Http\Request $request) {
                    return \App\Modules\Knowledge\Actions\ReplayDeletedKnowledgeCommand::handle($request);
                })
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.delete'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'shelf')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/books', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController::class, 'index'])
                ->name('knowledge.books.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', ['q', 'page', 'per_page', 'source_system', 'sync_status', 'shelf_id'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['post'], 'knowledge/books', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController::class, 'store'])
                ->name('knowledge.books.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
                ->defaults('knowledge_ability', 'knowledge.create')
                ->defaults('knowledge_permissions', ['knowledge.create'])
                ->defaults('knowledge_fields', ['shelf_id', 'name', 'description', 'priority', 'sync_to_book_stack'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/books/{book}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController::class, 'show'])
                ->name('knowledge.books.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'book')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.update']);
            Route::match(['put', 'patch'], 'knowledge/books/{book}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController::class, 'update'])
                ->name('knowledge.books.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.update'])
                ->defaults('knowledge_fields', ['shelf_id', 'name', 'description', 'priority', 'sync_to_book_stack'])
                ->defaults('knowledge_target', 'book')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['delete'], 'knowledge/books/{book}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeBookController::class, 'destroy'])
                ->name('knowledge.books.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->missing(function (\Illuminate\Http\Request $request) {
                    return \App\Modules\Knowledge\Actions\ReplayDeletedKnowledgeCommand::handle($request);
                })
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.delete'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'book')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/chapters', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController::class, 'index'])
                ->name('knowledge.chapters.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', ['q', 'page', 'per_page', 'source_system', 'sync_status', 'book_id'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['post'], 'knowledge/chapters', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController::class, 'store'])
                ->name('knowledge.chapters.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
                ->defaults('knowledge_ability', 'knowledge.create')
                ->defaults('knowledge_permissions', ['knowledge.create'])
                ->defaults('knowledge_fields', ['book_id', 'name', 'description', 'priority', 'sync_to_book_stack'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/chapters/{chapter}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController::class, 'show'])
                ->name('knowledge.chapters.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'chapter')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.update']);
            Route::match(['put', 'patch'], 'knowledge/chapters/{chapter}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController::class, 'update'])
                ->name('knowledge.chapters.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.update'])
                ->defaults('knowledge_fields', ['book_id', 'name', 'description', 'priority', 'sync_to_book_stack'])
                ->defaults('knowledge_target', 'chapter')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['delete'], 'knowledge/chapters/{chapter}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeChapterController::class, 'destroy'])
                ->name('knowledge.chapters.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->missing(function (\Illuminate\Http\Request $request) {
                    return \App\Modules\Knowledge\Actions\ReplayDeletedKnowledgeCommand::handle($request);
                })
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.delete'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'chapter')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/articles', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController::class, 'index'])
                ->name('knowledge.articles.index')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', ['q', 'page', 'per_page', 'status', 'visibility', 'book_id', 'shelf_id', 'chapter_id', 'category_id', 'client_scope_id', 'review_due'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['post'], 'knowledge/articles', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController::class, 'store'])
                ->name('knowledge.articles.store')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.create')
                ->defaults('knowledge_ability', 'knowledge.create')
                ->defaults('knowledge_permissions', ['knowledge.manage_drafts'])
                ->defaults('knowledge_fields', ['title', 'body_markdown', 'visibility', 'status', 'category_id', 'client_scope_id', 'knowledge_shelf_id', 'knowledge_book_id', 'knowledge_chapter_id', 'priority', 'next_review_at', 'sync_to_book_stack'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/articles/{article}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController::class, 'show'])
                ->name('knowledge.articles.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.manage_drafts']);
            Route::match(['put', 'patch'], 'knowledge/articles/{article}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController::class, 'update'])
                ->name('knowledge.articles.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.manage_drafts'])
                ->defaults('knowledge_fields', ['title', 'body_markdown', 'visibility', 'status', 'category_id', 'client_scope_id', 'knowledge_shelf_id', 'knowledge_book_id', 'knowledge_chapter_id', 'priority', 'next_review_at', 'sync_to_book_stack'])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['delete'], 'knowledge/articles/{article}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeArticleController::class, 'destroy'])
                ->name('knowledge.articles.destroy')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->missing(function (\Illuminate\Http\Request $request) {
                    return \App\Modules\Knowledge\Actions\ReplayDeletedKnowledgeCommand::handle($request);
                })
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.delete'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', []);
            Route::match(['get'], 'knowledge/context', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'context'])
                ->name('knowledge.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.manage_drafts', 'knowledge.create', 'knowledge.update', 'knowledge.manage_settings']);
            Route::match(['get'], 'knowledge/lookups', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'lookups'])
                ->name('knowledge.lookups')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', ['type', 'q', 'page', 'per_page', 'shelf_id', 'book_id'])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_any_permissions', ['knowledge.view', 'knowledge.manage_drafts', 'knowledge.create', 'knowledge.update', 'knowledge.manage_settings']);
            Route::match(['get'], 'knowledge/articles/{article}/revisions', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'revisions'])
                ->name('knowledge.revisions')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.read')
                ->defaults('knowledge_ability', 'knowledge.revisions.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', ['page', 'per_page'])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', false);
            Route::match(['get'], 'knowledge/revisions/{revision}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'revision'])
                ->name('knowledge.revision')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.read')
                ->defaults('knowledge_ability', 'knowledge.revisions.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_revision_access', 'view');
            Route::match(['post'], 'knowledge/revisions/{revision}/approve', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'approve'])
                ->name('knowledge.approve')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.approve')
                ->defaults('knowledge_ability', 'knowledge.revisions.approve')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', ['note'])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', true)
                ->defaults('knowledge_revision_access', 'approve');
            Route::match(['post'], 'knowledge/revisions/{revision}/reject', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'reject'])
                ->name('knowledge.reject')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.approve')
                ->defaults('knowledge_ability', 'knowledge.revisions.approve')
                ->defaults('knowledge_permissions', ['knowledge.approve'])
                ->defaults('knowledge_fields', ['note'])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', true);
            Route::match(['post'], 'knowledge/revisions/{revision}/publish', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'publish'])
                ->name('knowledge.publish')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.publish')
                ->defaults('knowledge_ability', 'knowledge.revisions.publish')
                ->defaults('knowledge_permissions', ['knowledge.publish'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', true);
            Route::match(['post'], 'knowledge/revisions/{revision}/retry', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'retry'])
                ->name('knowledge.retry')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.publish')
                ->defaults('knowledge_ability', 'knowledge.revisions.publish')
                ->defaults('knowledge_permissions', ['knowledge.publish'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', true);
            Route::match(['post'], 'knowledge/revisions/{revision}/rollback', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'rollback'])
                ->name('knowledge.rollback')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.revisions.rollback')
                ->defaults('knowledge_ability', 'knowledge.revisions.rollback')
                ->defaults('knowledge_permissions', ['knowledge.rollback'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'revision')
                ->defaults('knowledge_key_required', true);
            Route::match(['get'], 'knowledge/articles/{article}/book-stack', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'syncState'])
                ->name('knowledge.syncState')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.read')
                ->defaults('knowledge_ability', 'knowledge.read')
                ->defaults('knowledge_permissions', ['knowledge.view'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', false);
            Route::match(['post'], 'knowledge/articles/{article}/book-stack/accept-remote', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'acceptRemote'])
                ->name('knowledge.acceptRemote')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.manage_drafts'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', true);
            Route::match(['post'], 'knowledge/articles/{article}/book-stack/keep-nexum', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'keepNexum'])
                ->name('knowledge.keepNexum')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.sync')
                ->defaults('knowledge_ability', 'knowledge.sync')
                ->defaults('knowledge_permissions', ['knowledge.update'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', true)
                ->defaults('knowledge_external', true);
            Route::match(['get'], 'knowledge/settings', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'settings'])
                ->name('knowledge.settings')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.settings.read')
                ->defaults('knowledge_ability', 'knowledge.settings.read')
                ->defaults('knowledge_permissions', ['knowledge.manage_settings'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_key_required', false)
                ->defaults('knowledge_settings', true);
            Route::match(['put'], 'knowledge/settings', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'updateSettings'])
                ->name('knowledge.updateSettings')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.settings.write')
                ->defaults('knowledge_ability', 'knowledge.settings.write')
                ->defaults('knowledge_permissions', ['knowledge.manage_settings'])
                ->defaults('knowledge_fields', ['default_visibility', 'default_status', 'default_review_days', 'default_priority'])
                ->defaults('knowledge_key_required', true)
                ->defaults('knowledge_settings', true);
            Route::match(['post'], 'knowledge/articles/{article}/tags', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'attachTag'])
                ->name('knowledge.attachTag')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.manage_drafts'])
                ->defaults('knowledge_fields', ['tag_id', 'name'])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', true);
            Route::match(['delete'], 'knowledge/articles/{article}/tags/{tag}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'removeTag'])
                ->name('knowledge.removeTag')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.update')
                ->defaults('knowledge_ability', 'knowledge.update')
                ->defaults('knowledge_permissions', ['knowledge.manage_drafts'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_target', 'article')
                ->defaults('knowledge_key_required', true);
            Route::match(['post'], 'knowledge/book-stack/pull', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'pull'])
                ->name('knowledge.pull')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.sync')
                ->defaults('knowledge_ability', 'knowledge.sync')
                ->defaults('knowledge_permissions', ['integration.view'])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_key_required', true)
                ->defaults('knowledge_external', true);
            Route::match(['get'], 'knowledge/operations/{command}', [\App\Modules\Knowledge\Controllers\Api\V1\KnowledgeWorkspaceController::class, 'operation'])
                ->name('knowledge.operation')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':knowledge.operations.read')
                ->defaults('knowledge_ability', 'knowledge.operations.read')
                ->defaults('knowledge_permissions', [])
                ->defaults('knowledge_fields', [])
                ->defaults('knowledge_key_required', false);
        });

    return;
}

/*
|--------------------------------------------------------------------------
| Knowledge Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded from the authenticated /tech route group. Keep every
| Knowledge domain route in this file so the module remains independent of
| Laravel's default route files.
|
*/

if (($knowledgePortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/knowledge')
        ->name('customer-portal.knowledge.')
        ->group(function (): void {
            Route::get('/', [PortalKnowledgeController::class, 'index'])->name('index');
            Route::get('/{article}', [PortalKnowledgeController::class, 'show'])->name('show');
        });

    return;
}

Route::get('/admin/settings/knowledge', [KnowledgeSettingsController::class, 'edit'])->name('admin.settings.knowledge');
Route::put('/admin/settings/knowledge', [KnowledgeSettingsController::class, 'update'])->name('admin.settings.knowledge.update');

Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
Route::get('/knowledge/shelves/create', [KnowledgeController::class, 'createShelf'])->name('knowledge.shelves.create');
Route::post('/knowledge/shelves', [KnowledgeController::class, 'storeShelf'])->name('knowledge.shelves.store');
Route::get('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'shelf'])->name('knowledge.shelf');
Route::get('/knowledge/shelves/{shelf}/edit', [KnowledgeController::class, 'editShelf'])->name('knowledge.shelves.edit');
Route::put('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'updateShelf'])->name('knowledge.shelves.update');
Route::delete('/knowledge/shelves/{shelf}', [KnowledgeController::class, 'destroyShelf'])->name('knowledge.shelves.destroy');
Route::get('/knowledge/shelves/{shelf}/books/create', [KnowledgeController::class, 'createBook'])->name('knowledge.books.create');
Route::post('/knowledge/shelves/{shelf}/books', [KnowledgeController::class, 'storeBook'])->name('knowledge.books.store');
Route::get('/knowledge/books/{book}', [KnowledgeController::class, 'book'])->name('knowledge.book');
Route::get('/knowledge/books/{book}/edit', [KnowledgeController::class, 'editBook'])->name('knowledge.books.edit');
Route::put('/knowledge/books/{book}', [KnowledgeController::class, 'updateBook'])->name('knowledge.books.update');
Route::delete('/knowledge/books/{book}', [KnowledgeController::class, 'destroyBook'])->name('knowledge.books.destroy');
Route::get('/knowledge/books/{book}/chapters/create', [KnowledgeController::class, 'createChapter'])->name('knowledge.chapters.create');
Route::post('/knowledge/books/{book}/chapters', [KnowledgeController::class, 'storeChapter'])->name('knowledge.chapters.store');
Route::get('/knowledge/chapters/{chapter}/edit', [KnowledgeController::class, 'editChapter'])->name('knowledge.chapters.edit');
Route::put('/knowledge/chapters/{chapter}', [KnowledgeController::class, 'updateChapter'])->name('knowledge.chapters.update');
Route::delete('/knowledge/chapters/{chapter}', [KnowledgeController::class, 'destroyChapter'])->name('knowledge.chapters.destroy');
Route::get('/knowledge/books/{book}/pages/create', [KnowledgeController::class, 'createPageInBook'])->name('knowledge.books.pages.create');
Route::get('/knowledge/create', [KnowledgeController::class, 'create'])->name('knowledge.create');
Route::post('/knowledge/store', [KnowledgeController::class, 'store'])->name('knowledge.store');
Route::get('/knowledge/show/{article}', [KnowledgeController::class, 'show'])->name('knowledge.show');
Route::get('/knowledge/revisions/{revision}', [KnowledgeRevisionController::class, 'show'])->name('knowledge.revisions.show');
Route::post('/knowledge/revisions/{revision}/approve', [KnowledgeRevisionController::class, 'approve'])->name('knowledge.revisions.approve');
Route::post('/knowledge/revisions/{revision}/reject', [KnowledgeRevisionController::class, 'reject'])->name('knowledge.revisions.reject');
Route::post('/knowledge/revisions/{revision}/publish', [KnowledgeRevisionController::class, 'publish'])->name('knowledge.revisions.publish');
Route::post('/knowledge/revisions/{revision}/retry', [KnowledgeRevisionController::class, 'retry'])->name('knowledge.revisions.retry');
Route::post('/knowledge/revisions/{revision}/rollback', [KnowledgeRevisionController::class, 'rollback'])->name('knowledge.revisions.rollback');
Route::post('/knowledge/{article}/book-stack/accept-remote', [KnowledgeBookStackConflictController::class, 'acceptRemote'])->name('knowledge.book-stack.accept-remote');
Route::post('/knowledge/{article}/book-stack/keep-nexum', [KnowledgeBookStackConflictController::class, 'keepNexum'])->name('knowledge.book-stack.keep-nexum');
Route::get('/knowledge/edit/{article}', [KnowledgeController::class, 'edit'])->name('knowledge.edit');
Route::put('/knowledge/update/{article}', [KnowledgeController::class, 'update'])->name('knowledge.update');
Route::delete('/knowledge/destroy/{article}', [KnowledgeController::class, 'destroy'])->name('knowledge.destroy');
