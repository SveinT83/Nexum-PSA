<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** One conflict workflow for browser and API; selecting a candidate never publishes it. */
class ResolveArticleBookStackConflict
{
    public function acceptRemote(Article $article, int $actorId): ?\App\Models\Knowledge\ArticleRevision
    {
        return DB::transaction(function () use ($article, $actorId) {
            $state = ArticleBookStackSyncState::with('candidateRevision')->where('article_id', $article->id)->lockForUpdate()->first();
            $candidate = $state?->candidateRevision;
            if (! $candidate) {
                return null;
            }
            if (str_ends_with($candidate->state, '_candidate')) {
                $from = $candidate->state;
                $candidate->forceFill(['state' => 'ready_for_review', 'submitted_by' => $actorId, 'submitted_at' => now()])->save();
                app(RecordArticleRevisionEvent::class)->handle($candidate, 'submitted_for_review', $actorId, $from,
                    $candidate->state, 'BookStack candidate selected for the normal approval workflow.', ['provider' => 'book_stack']);
            }

            return $candidate->refresh();
        });
    }

    public function keepNexum(Article $article): ArticleBookStackSyncState
    {
        if ($article->status !== 'published') {
            throw ValidationException::withMessages(['article' => 'Publish the selected Nexum revision before resolving the conflict outbound.']);
        }
        $state = DB::transaction(function () use ($article) {
            $revision = app(ArticleRevisionIdentity::class)->recordCurrent($article, 'conflict_resolution');
            $state = ArticleBookStackSyncState::firstOrCreate(['article_id' => $article->id], ['external_type' => 'page', 'external_id' => $article->source_id]);
            $reason = $state->conflict_reason;
            if (blank($article->source_id) && $reason !== 'remote_record_deleted') {
                $reason = 'missing_external_identifier';
            }
            $state->forceFill(['pending_revision_id' => $revision->id, 'status' => ArticleBookStackSyncState::STATUS_RESOLVING_OUTBOUND,
                'last_direction' => 'outbound', 'origin' => 'conflict_resolution', 'conflict_reason' => $reason])->save();
            $article->forceFill(['sync_status' => 'pending_push'])->save();

            return $state;
        });
        PushPendingKnowledgeToBookStack::dispatch();

        return $state->refresh();
    }
}
