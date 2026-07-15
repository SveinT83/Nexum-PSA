<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use Illuminate\Database\Eloquent\Builder;

class PortalKnowledgeAccess
{
    public function visibleArticles(CustomerPortalContext $context): Builder
    {
        return Article::query()
            ->where('status', 'published')
            ->where(function (Builder $query) use ($context): void {
                $query->where('visibility', 'public')
                    ->orWhere(function (Builder $clientQuery) use ($context): void {
                        $clientQuery->where('visibility', 'client-wide')
                            ->where('client_scope_id', $context->client->id);
                    });
            });
    }

    public function canView(CustomerPortalContext $context, Article $article): bool
    {
        if ($article->status !== 'published') {
            return false;
        }

        if ($article->visibility === 'public') {
            return true;
        }

        return $article->visibility === 'client-wide'
            && (int) $article->client_scope_id === (int) $context->client->id;
    }
}
