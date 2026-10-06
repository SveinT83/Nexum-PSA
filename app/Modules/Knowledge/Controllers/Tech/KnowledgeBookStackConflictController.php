<?php

namespace App\Modules\Knowledge\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class KnowledgeBookStackConflictController extends Controller
{
    public function acceptRemote(
        Article $article,
        \App\Modules\Knowledge\Actions\RecordArticleRevisionEvent $events,
    ): RedirectResponse {
        $candidate = DB::transaction(function () use ($article, $events) {
            $state = ArticleBookStackSyncState::query()
                ->with('candidateRevision')
                ->where('article_id', $article->id)
                ->lockForUpdate()
                ->first();
            $candidate = $state?->candidateRevision;

            if (! $candidate) {
                return null;
            }

            if (str_ends_with($candidate->state, '_candidate')) {
                $from = $candidate->state;
                $candidate->forceFill([
                    'state' => 'ready_for_review',
                    'submitted_by' => Auth::id(),
                    'submitted_at' => now(),
                ])->save();
                $events->handle(
                    $candidate,
                    'submitted_for_review',
                    Auth::id(),
                    $from,
                    $candidate->state,
                    'BookStack candidate selected for the normal approval workflow.',
                    ['provider' => 'book_stack'],
                );
            }

            return $candidate->refresh();
        });

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

        DB::transaction(function () use ($article, $identity): void {
            $revision = $identity->recordCurrent($article, 'conflict_resolution');
            $state = ArticleBookStackSyncState::query()->firstOrCreate(
                ['article_id' => $article->id],
                ['external_type' => 'page', 'external_id' => $article->source_id],
            );
            $reason = $state->conflict_reason;

            if (blank($article->source_id) && $reason !== 'remote_record_deleted') {
                $reason = 'missing_external_identifier';
            }

            $state->forceFill([
                'pending_revision_id' => $revision->id,
                'status' => ArticleBookStackSyncState::STATUS_RESOLVING_OUTBOUND,
                'last_direction' => 'outbound',
                'origin' => 'conflict_resolution',
                'conflict_reason' => $reason,
            ])->save();
            $article->forceFill(['sync_status' => 'pending_push'])->save();
        });

        PushPendingKnowledgeToBookStack::dispatch();

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'The exact current Nexum revision was queued for conflict-resolving BookStack read-back.');
    }
}
