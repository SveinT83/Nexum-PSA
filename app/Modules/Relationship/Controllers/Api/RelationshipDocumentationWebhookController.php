<?php

namespace App\Modules\Relationship\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Relationship\Actions\AuthenticateNexumWebhook;
use App\Modules\Relationship\Actions\ReceiveRelationshipDocumentation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RelationshipDocumentationWebhookController extends Controller
{
    public function __invoke(Request $request, AuthenticateNexumWebhook $auth, ReceiveRelationshipDocumentation $receiver): JsonResponse
    {
        $relationship = $auth->handle($request);

        if (! $relationship) {
            return response()->json(['message' => 'Unauthenticated relationship payload.'], 401);
        }

        $data = $request->validate([
            'source_documentation_id' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'scope_type' => ['required', 'string', 'in:client,site,asset,shared'],
            'category' => ['nullable', 'array'],
            'category.name' => ['nullable', 'string', 'max:255'],
            'category.slug' => ['nullable', 'string', 'max:255'],
            'template' => ['nullable', 'array'],
            'template.name' => ['nullable', 'string', 'max:255'],
            'template.fields' => ['nullable', 'array'],
            'data' => ['nullable', 'array'],
            'content' => ['nullable', 'string'],
            'client' => ['nullable', 'array'],
            'site' => ['nullable', 'array'],
            'updated_at' => ['nullable', 'date'],
        ]);

        [$documentation, $link, $created, $conflict] = $receiver->handle($relationship, $data);

        return response()->json([
            'data' => [
                'id' => $documentation->id,
                'remote_id' => $documentation->id,
                'sync_link_id' => $link->id,
                'conflict' => $conflict,
            ],
            'created' => $created,
        ], $created ? 201 : 200);
    }
}
