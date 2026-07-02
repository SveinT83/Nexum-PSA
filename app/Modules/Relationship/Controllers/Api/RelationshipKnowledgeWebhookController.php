<?php

namespace App\Modules\Relationship\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Relationship\Actions\AuthenticateNexumWebhook;
use App\Modules\Relationship\Actions\ReceiveRelationshipKnowledgeArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationshipKnowledgeWebhookController extends Controller
{
    public function __invoke(Request $request, AuthenticateNexumWebhook $auth, ReceiveRelationshipKnowledgeArticle $receiver): JsonResponse
    {
        $relationship = $auth->handle($request);

        if (! $relationship) {
            return response()->json(['message' => 'Unauthenticated relationship payload.'], 401);
        }

        $data = $request->validate([
            'source_article_id' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'body_markdown' => ['required', 'string'],
            'visibility' => ['required', 'string', 'in:public,client-wide,shared'],
            'status' => ['nullable', 'string', 'max:255'],
            'client_scope_id' => ['nullable', 'integer'],
            'updated_at' => ['nullable', 'date'],
        ]);

        [$article, $link, $created, $conflict] = $receiver->handle($relationship, $data);

        return response()->json([
            'data' => [
                'id' => $article->id,
                'remote_id' => $article->id,
                'sync_link_id' => $link->id,
                'conflict' => $conflict,
            ],
            'created' => $created,
        ], $created ? 201 : 200);
    }
}
