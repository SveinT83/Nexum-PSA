<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Actor-serialized transport receipts wrap only explicit rule commands; domain actions still own every effect. */
final class ExecuteIdempotentTicketRuleApiCommand
{
    public function handle(User $actor, string $operation, string $key, array $payload, callable $command): Model
    {
        $model = match ($operation) {
            'publish' => TicketRuleVersion::class, 'retry' => TicketRuleActionResult::class,
            'legacy-create', 'enabled', 'delete' => TicketRule::class,
            default => throw new \LogicException('Unknown rule command.'),
        };

        return DB::transaction(function () use ($actor, $operation, $key, $payload, $command, $model) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $audit = app(DomainApiAudit::class);
            $keyHash = hash('sha256', $key);
            $fingerprint = TicketRuleStableJson::checksum($payload);
            $event = 'ticket.rule.api.'.$operation;
            $receipt = $audit->query('ticket')->where('causer_id', $actor->id)->where('event', $event)
                ->where('properties->context->idempotency_key_hash', $keyHash)->first();
            if ($receipt) {
                abort_unless(hash_equals((string) data_get($receipt->properties, 'context.request_fingerprint'), $fingerprint), 409, 'The request key was already used for a different rule command.');
                abort_unless($receipt->subject_type === (new $model)->getMorphClass(), 409);
                $query = $model::query();
                if ($model === TicketRule::class) {
                    $query->withTrashed();
                }
                $result = $query->find($receipt->subject_id);
                abort_unless($result, 409, 'The original rule command result is unavailable.');

                return $result;
            }
            $result = $command();
            $audit->record($actor, 'ticket', $event, $result, [], ['result_id' => $result->id], ['idempotency_key_hash' => $keyHash, 'request_fingerprint' => $fingerprint]);

            return $result;
        }, 3);
    }
}
