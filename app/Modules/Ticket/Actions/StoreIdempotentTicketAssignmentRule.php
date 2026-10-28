<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Services\TicketAssignmentRuleEditor;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Serialize an actor's create receipt in the existing journal without exposing a generic write dispatcher. */
class StoreIdempotentTicketAssignmentRule
{
    public function handle(User $actor, array $data, string $key): TicketAssignmentRule
    {
        $keyHash = hash('sha256', $key);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($actor, $data, $keyHash, $fingerprint) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $receipt = app(DomainApiAudit::class)->query('ticket')->where('event', 'ticket.assignment.rule.created')
                ->where('causer_type', $actor->getMorphClass())->where('causer_id', $actor->id)
                ->where('properties->context->idempotency_key_hash', $keyHash)->first();
            if ($receipt) {
                $context = $receipt->properties->get('context', []);
                $rule = TicketAssignmentRule::withTrashed()->find($receipt->subject_id);
                if (! $rule || $rule->trashed() || ! hash_equals((string) ($context['request_fingerprint'] ?? ''), $fingerprint)) {
                    throw new ConflictHttpException('The assignment-rule key is reserved for a different or deleted result.');
                }

                return $rule;
            }

            return app(TicketAssignmentRuleEditor::class)->create($data, $actor, ['idempotency_key_hash' => $keyHash, 'request_fingerprint' => $fingerprint]);
        });
    }
}
