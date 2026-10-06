<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Core\User;
use App\Models\Knowledge\ArticleRevision;

/**
 * Exact-object authorization for Ticket-scoped AI revision review.
 */
class KnowledgeRevisionAccess
{
    public function canView(User $user, ArticleRevision $revision): bool
    {
        return $user->hasPermissionTo('knowledge.view')
            || $this->hasTicketScope($user, $revision);
    }

    public function canApprove(User $user, ArticleRevision $revision): bool
    {
        if ($user->hasPermissionTo('knowledge.approve')) {
            return true;
        }

        return $revision->ai_agent_id !== null
            && $revision->ticket_id !== null
            && $this->hasTicketScope($user, $revision);
    }

    private function hasTicketScope(User $user, ArticleRevision $revision): bool
    {
        if ($revision->ai_agent_id === null
            || $revision->ticket_id === null
            || $revision->documentation_request_id === null
            || ! $user->hasPermissionTo('ticket.view')
            || ! $user->hasPermissionTo('ticket.update')) {
            return false;
        }

        $request = $revision->documentationRequest()->first();

        return $request !== null
            && (int) $request->ticket_id === (int) $revision->ticket_id
            && $request->status !== \App\Models\Knowledge\DocumentationRequest::STATUS_COMPLETED;
    }
}
