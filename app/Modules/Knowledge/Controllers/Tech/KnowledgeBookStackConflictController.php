<?php

namespace App\Modules\Knowledge\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class KnowledgeBookStackConflictController extends Controller
{
    public function acceptRemote(
        Article $article,
        \App\Modules\Knowledge\Actions\RecordArticleRevisionEvent $events,
    ): RedirectResponse {
        $candidate = app(\App\Modules\Knowledge\Actions\ResolveArticleBookStackConflict::class)
            ->acceptRemote($article, (int) Auth::id());

        if (! $candidate) {
            return redirect()->route('tech.knowledge.show', $article)
                ->with('warning', 'No BookStack candidate is available to review.');
        }

        return redirect()->route('tech.knowledge.revisions.show', $candidate)
            ->with('success', 'BookStack candidate opened as a proposal. Published content is unchanged.');
    }

    public function keepNexum(Article $article, ArticleRevisionIdentity $identity): RedirectResponse
    {
        if ($article->status !== 'published') {
            return redirect()->route('tech.knowledge.show', $article)
                ->with('warning', 'Publish the selected Nexum revision before resolving the conflict outbound.');
        }

        app(\App\Modules\Knowledge\Actions\ResolveArticleBookStackConflict::class)->keepNexum($article);

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'The exact current Nexum revision was queued for conflict-resolving BookStack read-back.');
    }
}
