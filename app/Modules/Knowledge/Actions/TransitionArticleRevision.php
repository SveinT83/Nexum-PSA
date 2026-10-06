<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\DocumentationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies explicit, audited review decisions to an immutable revision.
 */
class TransitionArticleRevision
{
    public function __construct(private readonly RecordArticleRevisionEvent $events) {}

    public function approve(ArticleRevision $revision, int $actorId, ?string $note = null): ArticleRevision
    {
        return $this->decision($revision, ArticleRevision::STATE_APPROVED, $actorId, $note);
    }

    public function reject(ArticleRevision $revision, int $actorId, ?string $note = null): ArticleRevision
    {
        return $this->decision($revision, ArticleRevision::STATE_REJECTED, $actorId, $note);
    }

    public function submit(ArticleRevision $revision, int $actorId): ArticleRevision
    {
        return DB::transaction(function () use ($revision, $actorId): ArticleRevision {
            $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($revision->state !== ArticleRevision::STATE_DRAFT) {
                throw ValidationException::withMessages(['revision' => 'Only a draft can be submitted for review.']);
            }

            $from = $revision->state;
            $revision->forceFill([
                'state' => ArticleRevision::STATE_READY_FOR_REVIEW,
                'submitted_by' => $actorId,
                'submitted_at' => now(),
            ])->save();
            $this->events->handle($revision, 'submitted_for_review', $actorId, $from, $revision->state);

            return $revision->refresh();
        });
    }

    private function decision(ArticleRevision $revision, string $toState, int $actorId, ?string $note): ArticleRevision
    {
        $revision->refresh();

        if ($toState === ArticleRevision::STATE_APPROVED
            && (int) $revision->base_revision_id !== (int) $revision->article()->value('published_revision_id')) {
            DB::transaction(function () use ($revision, $actorId): void {
                $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);
                $from = $revision->state;
                $revision->forceFill([
                    'state' => ArticleRevision::STATE_CONFLICT,
                    'decision_note' => 'The published base changed before approval.',
                ])->save();
                $this->events->handle($revision, 'stale_base_conflict', $actorId, $from, $revision->state, metadata: [
                    'reason_code' => 'published_base_changed',
                ]);
            });

            throw ValidationException::withMessages([
                'revision' => 'The published article changed. Create a new proposal from the current revision.',
            ]);
        }

        return DB::transaction(function () use ($revision, $toState, $actorId, $note): ArticleRevision {
            $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $article = Article::query()->lockForUpdate()->findOrFail($revision->article_id);

            if (! in_array($revision->state, [ArticleRevision::STATE_DRAFT, ArticleRevision::STATE_READY_FOR_REVIEW], true)) {
                throw ValidationException::withMessages(['revision' => 'This revision is no longer awaiting a review decision.']);
            }

            if ($toState === ArticleRevision::STATE_APPROVED
                && (int) $revision->base_revision_id !== (int) $article->published_revision_id) {
                throw ValidationException::withMessages([
                    'revision' => 'The published article changed during approval.',
                ]);
            }

            $from = $revision->state;
            $attributes = [
                'state' => $toState,
                'decision_note' => $note,
            ];

            if ($toState === ArticleRevision::STATE_APPROVED) {
                $attributes += ['approved_by' => $actorId, 'approved_at' => now()];
            } else {
                $attributes += ['rejected_by' => $actorId, 'rejected_at' => now()];
            }

            $revision->forceFill($attributes)->save();
            $this->events->handle(
                $revision,
                $toState === ArticleRevision::STATE_APPROVED ? 'approved' : 'rejected',
                $actorId,
                $from,
                $toState,
                $note,
            );

            if ($revision->documentation_request_id) {
                DocumentationRequest::query()->whereKey($revision->documentation_request_id)->update([
                    'status' => $toState === ArticleRevision::STATE_APPROVED
                        ? DocumentationRequest::STATUS_APPROVED
                        : DocumentationRequest::STATUS_IN_REVIEW,
                ]);
            }

            return $revision->refresh();
        });
    }
}
