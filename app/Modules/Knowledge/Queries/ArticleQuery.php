<?php

namespace App\Modules\Knowledge\Queries;

use App\Models\Knowledge\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Read model for the Knowledge article index.
 *
 * Keeping the list query outside the controller makes future filtering
 * additions straightforward: status, category, owner, visibility, client scope,
 * review due date, and full-text search can be added here without expanding
 * the controller.
 */
class ArticleQuery
{
    /**
     * Return paginated articles for the Tech knowledge base list.
     *
     * The index displays category and owner metadata, so those relations are
     * eager-loaded to avoid N+1 queries in the table.
     */
    public function paginateForTechIndex(int $perPage = 20): LengthAwarePaginator
    {
        return Article::query()
            ->with(['category', 'owner'])
            ->latest()
            ->paginate($perPage);
    }
}
