<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Knowledge\Support\DocumentationAgentActor;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Persists AI output under a named agent and an exact Ticket authorization scope.
 */
class CreateTicketDocumentationRevision
{
    public function __construct(
        private readonly DocumentationAgentActor $actors,
        private readonly CreateArticleRevision $createRevision,
    ) {}

    /** @param array<string, mixed> $data */
    public function handle(
        DocumentationRequest $request,
        Article $article,
        AiAgent $agent,
        array $data,
    ): ArticleRevision {
        $actor = $this->actors->resolve();

        if (! $actor->hasPermissionTo('knowledge.revision_persist')) {
            throw new AuthorizationException('The Documentation Agent may not persist Knowledge revisions.');
        }

        if ($request->status === DocumentationRequest::STATUS_COMPLETED) {
            throw new AuthorizationException('Completed documentation work cannot accept another AI revision.');
        }

        return $this->createRevision->handle(
            article: $article,
            data: $data,
            origin: 'ai',
            actorId: $actor->id,
            state: ArticleRevision::STATE_READY_FOR_REVIEW,
            provenance: [
                'source_system' => 'nexum_ai',
                'source_type' => 'ticket_documentation_request',
                'source_id' => (string) $request->id,
                'source_version' => (string) $agent->updated_at?->timestamp,
                'ai_agent_id' => $agent->id,
                'system_actor_id' => $actor->id,
                'ticket_id' => $request->ticket_id,
                'documentation_request_id' => $request->id,
            ],
        );
    }
}
