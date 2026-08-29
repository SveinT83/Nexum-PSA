<?php

namespace App\Modules\Ticket\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Protect terminal Ticket Rule evidence from accidental Eloquent mutation.
 *
 * Database guards enforce the same contract for query-builder and direct SQL writes.
 */
abstract class TicketRuleEvidence extends Model
{
    protected $guarded = [];

    /** @return list<string> */
    abstract protected static function terminalStatuses(): array;

    protected static function completionTimestampColumn(): string
    {
        return 'completed_at';
    }

    protected static function booted(): void
    {
        static::updating(function (self $evidence): void {
            $completedAt = $evidence->getRawOriginal(static::completionTimestampColumn());

            if ($completedAt !== null
                || in_array((string) $evidence->getRawOriginal('status'), static::terminalStatuses(), true)) {
                throw new LogicException('Completed Ticket Rule evidence is immutable.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Ticket Rule evidence cannot be deleted.');
        });
    }
}
