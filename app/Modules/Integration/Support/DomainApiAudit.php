<?php

namespace App\Modules\Integration\Support;

use App\Models\Core\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/** Reuse the existing activity journal without copying notes, content, credentials or request bodies. */
final class DomainApiAudit
{
    public function record(User $actor, string $domain, string $operation, ?Model $subject, array $before, array $after, array $context = []): void
    {
        $before = $this->normalize(Arr::except($before, ['created_at', 'updated_at']));
        $after = $this->normalize(Arr::except($after, ['created_at', 'updated_at']));
        if ($before === $after) {
            return;
        }
        $changed = collect(array_unique(array_merge(array_keys($before), array_keys($after))))
            ->filter(fn ($key) => ($before[$key] ?? null) !== ($after[$key] ?? null))->values()->all();
        $logger = activity('domain_api')->causedBy($actor)->event($operation)->withProperties([
            'domain' => $domain, 'changed_fields' => $changed,
            'before_fingerprint' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR)),
            'after_fingerprint' => hash('sha256', json_encode($after, JSON_THROW_ON_ERROR)),
            'context' => Arr::only($context, ['template_group_id', 'schedule_id', 'profile_user_id', 'idempotency_key_hash', 'request_fingerprint']),
        ]);
        if ($subject) {
            $logger->performedOn($subject);
        }
        if (! $logger->log($operation)) {
            // Callers keep this inside the domain transaction: a disabled journal cannot silently drop evidence.
            throw ValidationException::withMessages(['audit' => 'Activity logging is required for this configuration change.']);
        }
    }

    public function query(string $domain)
    {
        return Activity::query()->where('log_name', 'domain_api')->where('properties->domain', $domain);
    }

    private function normalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->normalize($item);
            }
        }

        return $value;
    }
}
