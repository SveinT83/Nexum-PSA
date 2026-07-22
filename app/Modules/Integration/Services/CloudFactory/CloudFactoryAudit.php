<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\CloudFactory\AuditEvent;
use Illuminate\Database\Eloquent\Model;

class CloudFactoryAudit
{
    public function record(
        string $event,
        ?Integration $integration = null,
        ?int $clientId = null,
        Model|string|null $subject = null,
        array $metadata = [],
        ?int $actorId = null,
    ): AuditEvent {
        return AuditEvent::query()->create([
            'integration_id' => $integration?->id,
            'client_id' => $clientId,
            'actor_id' => $actorId ?? auth()->id(),
            'event' => $event,
            'subject_type' => $subject instanceof Model ? $subject->getMorphClass() : null,
            'subject_id' => $subject instanceof Model ? (string) $subject->getKey() : ($subject ?: null),
            'metadata' => $this->sanitize($metadata),
        ]);
    }

    private function sanitize(array $metadata): array
    {
        $blocked = ['token', 'access_token', 'refresh_token', 'id_token', 'authorization', 'password', 'secret'];

        return collect($metadata)
            ->reject(fn (mixed $value, string|int $key): bool => in_array(strtolower((string) $key), $blocked, true))
            ->map(function (mixed $value) use ($blocked): mixed {
                if (! is_array($value)) {
                    return $value;
                }

                return collect($value)
                    ->reject(fn (mixed $nested, string|int $key): bool => in_array(strtolower((string) $key), $blocked, true))
                    ->all();
            })
            ->all();
    }
}
