<?php

namespace App\Modules\Relationship\Support;

use App\Models\Knowledge\Article;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Relationship\Models\NexumRelationship;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Relationship export never substitutes for current source-domain read authority. */
class RelationshipContentAccess
{
    public static function query(NexumRelationship $relationship, string $type): Builder
    {
        RelationshipApiAccess::permit(RelationshipApiAccess::actor(), $type === 'documentation' ? 'documentation.view' : 'knowledge.view');
        $query = $type === 'documentation'
            ? Documentation::query()->where('scope_type', '!=', 'internal')
            : Article::query()->where('visibility', '!=', 'internal')->where('status', 'published');
        if ($relationship->isProviderForClient()) {
            if ($type === 'documentation') {
                $query->where('client_id', $relationship->client_id);
            } else {
                $query->where(fn ($q) => $q->where('visibility', 'public')->orWhere('client_scope_id', $relationship->client_id));
            }
        }

        return $query;
    }

    public static function source(NexumRelationship $relationship, string $type, int $id): Model
    {
        return self::query($relationship, $type)->findOrFail($id);
    }

    public static function summary(Model $source): array
    {
        return array_merge($source->only(['id', 'title']), [
            'version' => RelationshipProjection::version($source),
            'client_id' => $source instanceof Documentation ? $source->client_id : $source->client_scope_id,
            'scope' => $source instanceof Documentation ? $source->scope_type : $source->visibility,
        ]);
    }
}
