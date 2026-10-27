<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Modules\Knowledge\Actions\ExecuteKnowledgeApiCommand;
use App\Modules\Knowledge\Actions\ManageArticleTag;
use App\Modules\Knowledge\Actions\PublishArticleRevision;
use App\Modules\Knowledge\Actions\ResolveArticleBookStackConflict;
use App\Modules\Knowledge\Actions\RollbackArticleRevision;
use App\Modules\Knowledge\Actions\TransitionArticleRevision;
use App\Modules\Knowledge\Models\KnowledgeApiCommand;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeArticleResource;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeRevisionResource;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Typed entry points adapt the existing domain actions; they do not own a second workflow. */
class KnowledgeWorkspaceController extends Controller
{
    public function context(Request $request, KnowledgeSettings $settings, KnowledgeBookStackSync $sync)
    {
        $permissions = ['knowledge.view', 'knowledge.manage_drafts', 'knowledge.create', 'knowledge.update',
            'knowledge.delete', 'knowledge.approve', 'knowledge.publish', 'knowledge.rollback', 'knowledge.manage_settings', 'knowledge.sync_bookstack'];

        return response()->json(['data' => ['defaults' => $settings->articleDefaults(),
            'visibility_options' => KnowledgeSettings::VISIBILITY_OPTIONS, 'status_options' => KnowledgeSettings::STATUS_OPTIONS,
            'permissions' => collect($permissions)->mapWithKeys(fn ($p) => [$p => $request->user()->hasPermissionTo($p)])->all(),
            'book_stack' => ['can_pull' => $sync->canPull(), 'two_way_enabled' => $sync->twoWayEnabled(),
                'api_provider_execution_enabled' => (bool) config('knowledge-api.provider_execution_enabled')],
            'article_writes_create_proposals' => true, 'tags_are_live_metadata' => true]]);
    }

    public function lookups(Request $request)
    {
        $data = $request->validate(['type' => 'required|in:shelves,books,chapters,categories,clients,tags',
            'shelf_id' => 'sometimes|nullable|integer|min:1', 'book_id' => 'sometimes|integer|min:1']);
        $model = ['shelves' => Shelf::class, 'books' => Book::class, 'chapters' => Chapter::class,
            'categories' => Category::class, 'clients' => Client::class, 'tags' => Tag::class][$data['type']];
        $query = $model::query();
        if (in_array($data['type'], ['clients', 'tags'], true)) {
            $query->where('active', true);
        }
        if ($data['type'] === 'books' && $request->has('shelf_id')) {
            $query->where('shelf_id', $request->input('shelf_id'));
        }
        if ($data['type'] === 'chapters' && $request->has('book_id')) {
            $query->where('book_id', $request->integer('book_id'));
        }
        if ($request->filled('q')) {
            $query->where('name', 'like', '%'.$request->input('q').'%');
        }

        return response()->json($query->select(['id', 'name'])->orderBy('name')->paginate($request->integer('per_page') ?: 25));
    }

    public function revisions(Request $request, Article $article)
    {
        return KnowledgeRevisionResource::collection($article->revisions()->orderByDesc('revision_number')->paginate($request->integer('per_page') ?: 25));
    }

    public function revision(Request $request, ArticleRevision $revision)
    {
        return new KnowledgeRevisionResource($revision);
    }

    public function approve(Request $request, ArticleRevision $revision, TransitionArticleRevision $transition)
    {
        $data = $request->validate(['note' => 'nullable|string|max:2000']);

        return new KnowledgeRevisionResource($transition->approve($revision, $request->user()->id, $data['note'] ?? null));
    }

    public function reject(Request $request, ArticleRevision $revision, TransitionArticleRevision $transition)
    {
        $data = $request->validate(['note' => 'required|string|max:2000']);

        return new KnowledgeRevisionResource($transition->reject($revision, $request->user()->id, $data['note']));
    }

    public function publish(Request $request, ArticleRevision $revision, PublishArticleRevision $publish)
    {
        return new KnowledgeRevisionResource($publish->handle($revision, $request->user()->id));
    }

    public function retry(Request $request, ArticleRevision $revision, PublishArticleRevision $publish)
    {
        return new KnowledgeRevisionResource($publish->handle($revision, $request->user()->id));
    }

    public function rollback(Request $request, ArticleRevision $revision, RollbackArticleRevision $rollback)
    {
        return (new KnowledgeRevisionResource($rollback->handle($revision, $request->user()->id)))->response()->setStatusCode(201);
    }

    public function syncState(Article $article)
    {
        $state = $article->bookStackSyncState;

        return response()->json(['data' => ['article_id' => $article->id, 'published_revision_id' => $article->published_revision_id,
            'sync_status' => $article->sync_status,
            'state' => $state?->only(['status', 'candidate_revision_id', 'pending_revision_id', 'last_synced_revision_id',
                'external_id', 'external_url', 'last_direction', 'last_synced_at', 'conflict_reason'])]]);
    }

    public function acceptRemote(Request $request, Article $article, ResolveArticleBookStackConflict $resolve)
    {
        $revision = $resolve->acceptRemote($article, $request->user()->id);
        abort_unless($revision, 409, 'No BookStack candidate is available to review.');

        return new KnowledgeRevisionResource($revision);
    }

    public function keepNexum(Request $request, Article $article, ResolveArticleBookStackConflict $resolve)
    {
        $resolve->keepNexum($article);

        return $this->syncState($article->refresh());
    }

    public function settings(KnowledgeSettings $settings)
    {
        return response()->json(['data' => $settings->get()]);
    }

    public function updateSettings(Request $request, KnowledgeSettings $settings)
    {
        return response()->json(['data' => $settings->update($request->validate(KnowledgeSettings::rules()))]);
    }

    public function attachTag(Request $request, Article $article, ManageArticleTag $tags)
    {
        if ($request->has('tag_id') && $request->has('name')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tag_id' => 'Choose either tag_id or name.']);
        }
        $data = $request->validate(['tag_id' => ['required_without:name', 'integer', Rule::exists('tags', 'id')->whereNull('deleted_at')],
            'name' => 'required_without:tag_id|string|max:255']);

        return new KnowledgeArticleResource($tags->handle($article, $data['tag_id'] ?? null, $data['name'] ?? null));
    }

    public function removeTag(Request $request, Article $article, Tag $tag, ManageArticleTag $tags)
    {
        return new KnowledgeArticleResource($tags->handle($article, $tag->id, remove: true));
    }

    public function pull(Request $request, KnowledgeBookStackSync $sync)
    {
        abort_unless($sync->canPull() && $sync->client(), 409, 'BookStack pull is not configured and active.');
        // The Integration action owns comparison, candidates, conflict and exact sync state.
        $summary = (new \App\Modules\Integration\Actions\SyncBookStackToKnowledge($sync->integration(), $sync->client(), $request->user()))->execute();

        return response()->json(['data' => collect($summary)->only(['created', 'updated', 'skipped', 'failed', 'total', 'candidates', 'conflicts'])->all()]);
    }

    public function operation(Request $request, KnowledgeApiCommand $command)
    {
        ExecuteKnowledgeApiCommand::authorizeReceipt($request, $command);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation, 'state' => $command->state,
            'http_status' => $command->result['status'], 'result' => $command->result['body'],
            'reconciliation_required' => in_array($command->state, ['running', 'unknown'], true), 'created_at' => $command->created_at]]);
    }
}
