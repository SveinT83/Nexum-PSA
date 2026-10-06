<?php

namespace App\Modules\Knowledge\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\ArticleRevision;
use App\Modules\Knowledge\Actions\PublishArticleRevision;
use App\Modules\Knowledge\Actions\RollbackArticleRevision;
use App\Modules\Knowledge\Actions\TransitionArticleRevision;
use App\Modules\Knowledge\Support\ArticleRevisionDiff;
use App\Modules\Knowledge\Support\KnowledgeRevisionAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class KnowledgeRevisionController extends Controller
{
    public function show(
        Request $request,
        ArticleRevision $revision,
        KnowledgeRevisionAccess $access,
        ArticleRevisionDiff $diff,
    ): View {
        abort_unless($access->canView($request->user(), $revision), 403);

        $revision->load([
            'article.publishedRevision',
            'baseRevision',
            'creator',
            'humanAuthor',
            'aiAgent',
            'ticket',
            'documentationRequest',
            'approver',
            'publisher',
            'events.actor',
        ]);
        $base = $revision->baseRevision ?: $revision->article->publishedRevision;

        return view('knowledge::Tech.revision', [
            'revision' => $revision,
            'base' => $base,
            'diff' => $diff->between((string) $base?->body_markdown, $revision->body_markdown),
            'canApprove' => $access->canApprove($request->user(), $revision),
        ]);
    }

    public function approve(
        Request $request,
        ArticleRevision $revision,
        KnowledgeRevisionAccess $access,
        TransitionArticleRevision $transition,
    ): RedirectResponse {
        abort_unless($access->canApprove($request->user(), $revision), 403);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $transition->approve($revision, (int) $request->user()->id, $data['note'] ?? null);

        return back()->with('success', 'Exact revision approved. It is not published yet.');
    }

    public function reject(
        Request $request,
        ArticleRevision $revision,
        TransitionArticleRevision $transition,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermissionTo('knowledge.approve'), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $transition->reject($revision, (int) $request->user()->id, $data['note']);

        return back()->with('success', 'Revision rejected; published content is unchanged.');
    }

    public function publish(
        Request $request,
        ArticleRevision $revision,
        PublishArticleRevision $publish,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermissionTo('knowledge.publish'), 403);
        $publish->handle($revision, (int) $request->user()->id);

        return back()->with('success', 'Approved revision publication started and local read-back passed.');
    }

    public function retry(
        Request $request,
        ArticleRevision $revision,
        PublishArticleRevision $publish,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermissionTo('knowledge.publish'), 403);
        $publish->handle($revision, (int) $request->user()->id);

        return back()->with('success', 'Publication retry started for the same immutable revision.');
    }

    public function rollback(
        Request $request,
        ArticleRevision $revision,
        RollbackArticleRevision $rollback,
    ): RedirectResponse {
        abort_unless($request->user()->hasPermissionTo('knowledge.rollback'), 403);
        $proposal = $rollback->handle($revision, (int) $request->user()->id);

        return redirect()->route('tech.knowledge.revisions.show', $proposal)
            ->with('success', 'Rollback proposal created as a new revision. History was preserved.');
    }
}
