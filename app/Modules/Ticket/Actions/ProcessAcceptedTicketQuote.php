<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Convert accepted Ticket quote scope into the next safe internal records.
 */
class ProcessAcceptedTicketQuote
{
    public function __construct(
        private readonly TicketQuoteDeliveryAutomationActor $automationActor,
        private readonly ConvertApprovedTicketPlannedLine $convertLine,
        private readonly RequestTicketPurchase $requestPurchase,
    ) {}

    /**
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function handle(Ticket $ticket, SalesQuoteVersion $version, ?User $acceptedBy = null): array
    {
        $version->loadMissing(['quote', 'lines', 'conversionPlans']);

        if ($version->status !== 'accepted') {
            return ['processed' => 0, 'skipped' => 0, 'failed' => 0];
        }

        $actor = $this->automationActor->resolve();
        $summary = ['processed' => 0, 'skipped' => 0, 'failed' => 0];
        $quoteLineIdsByPlannedLine = $this->quoteLineIdsByPlannedLine($version);

        $lines = TicketPlannedLine::query()
            ->with(['storageItem.primaryVendor', 'convertedCostEntry', 'purchaseOrderLine.purchaseOrder'])
            ->where('ticket_id', $ticket->id)
            ->where('approved_quote_version_id', $version->id)
            ->whereIn('status', ['approved', 'converted'])
            ->orderBy('id')
            ->get();

        foreach ($lines as $line) {
            $quoteLineId = $quoteLineIdsByPlannedLine[$line->id] ?? null;

            if ($line->converted_cost_entry_id || $line->purchaseOrderLine) {
                $record = $line->convertedCostEntry ?: $line->purchaseOrderLine;
                if ($record) {
                    $this->markConversionPlanCompleted($version, $quoteLineId, $record, $actor);
                }
                $summary['skipped']++;

                continue;
            }

            try {
                $record = $this->processLine($ticket, $line, $actor);
                $line = $line->refresh()->load(['convertedCostEntry', 'purchaseOrderLine.purchaseOrder']);
                $this->markLineAutomationState($line, $version, 'completed', $record);
                $this->markConversionPlanCompleted($version, $quoteLineId, $record, $actor);
                $this->recordLineEvent($ticket, $line, $version, $record, $actor);
                $summary['processed']++;
            } catch (Throwable $exception) {
                $reason = $this->exceptionMessage($exception);
                $line = $line->fresh() ?: $line;
                $this->markLineAutomationState($line, $version, 'blocked', null, $reason);
                $this->markConversionPlanBlocked($version, $quoteLineId, $reason, $actor);
                $this->recordLineFailure($ticket, $line, $version, $reason, $actor);
                Log::warning('Accepted Ticket quote line could not be processed automatically.', [
                    'ticket_id' => $ticket->id,
                    'quote_version_id' => $version->id,
                    'ticket_planned_line_id' => $line->id,
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private function processLine(Ticket $ticket, TicketPlannedLine $line, User $actor): Model
    {
        if (! $line->storage_item_id) {
            return $this->convertLine->handle($ticket, $line, $actor);
        }

        $line->loadMissing('storageItem.primaryVendor');
        $item = $line->storageItem;
        if (! $item || (method_exists($item, 'trashed') && $item->trashed()) || $item->status !== 'active') {
            throw ValidationException::withMessages([
                'planned_line' => 'The accepted Storage item is no longer active.',
            ]);
        }

        $quantity = max(1, (int) ceil((float) $line->quantity));
        $item->refresh();

        if ((int) $item->qty_available >= $quantity) {
            return $this->convertLine->handle($ticket, $line, $actor);
        }

        if ($item->can_be_ordered) {
            return $this->requestPurchase->handle($ticket, $line, $actor);
        }

        throw ValidationException::withMessages([
            'planned_line' => 'The accepted Storage item is short on stock and cannot be ordered.',
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function quoteLineIdsByPlannedLine(SalesQuoteVersion $version): array
    {
        $mapped = [];

        foreach ($version->lines as $line) {
            $plannedLineId = (int) data_get($line->snapshot, 'ticket_planned_line_id');
            if ($plannedLineId > 0) {
                $mapped[$plannedLineId] = (int) $line->id;
            }
        }

        return $mapped;
    }

    private function markLineAutomationState(
        TicketPlannedLine $line,
        SalesQuoteVersion $version,
        string $status,
        ?Model $record = null,
        ?string $reason = null,
    ): void {
        $metadata = $line->metadata ?: [];
        $metadata['accepted_quote_processing'] = array_filter([
            'status' => $status,
            'processed_at' => now()->toISOString(),
            'quote_version_id' => $version->id,
            'record_type' => $record?->getMorphClass(),
            'record_id' => $record?->getKey(),
            'reason' => $reason,
        ], static fn ($value): bool => $value !== null);

        $line->forceFill(['metadata' => $metadata])->save();
    }

    private function markConversionPlanCompleted(
        SalesQuoteVersion $version,
        ?int $quoteLineId,
        Model $record,
        User $actor,
    ): void {
        $plan = $this->conversionPlan($version, $quoteLineId);
        if (! $plan) {
            return;
        }

        $plan->forceFill([
            'status' => 'completed',
            'created_record_type' => $record->getMorphClass(),
            'created_record_id' => $record->getKey(),
            'target_reference' => $this->recordReference($record),
            'operator_note' => 'Processed automatically after the customer accepted the Ticket quote.',
            'processed_at' => now(),
            'processed_by' => $actor->id,
        ])->save();
    }

    private function markConversionPlanBlocked(
        SalesQuoteVersion $version,
        ?int $quoteLineId,
        string $reason,
        User $actor,
    ): void {
        $plan = $this->conversionPlan($version, $quoteLineId);
        if (! $plan) {
            return;
        }

        $plan->forceFill([
            'status' => 'blocked',
            'target_reference' => null,
            'operator_note' => 'Automatic processing failed: '.$reason,
            'processed_at' => now(),
            'processed_by' => $actor->id,
        ])->save();
    }

    private function conversionPlan(SalesQuoteVersion $version, ?int $quoteLineId): ?SalesQuoteConversionPlan
    {
        if (! $quoteLineId) {
            return null;
        }

        return SalesQuoteConversionPlan::query()
            ->where('quote_version_id', $version->id)
            ->where('quote_line_id', $quoteLineId)
            ->first();
    }

    private function recordReference(Model $record): string
    {
        if ($record instanceof TicketCostEntry) {
            return 'Ticket cost #'.$record->id;
        }

        if ($record instanceof PurchaseOrderLine) {
            return 'Purchase need #'.$record->id;
        }

        return class_basename($record).' #'.$record->getKey();
    }

    private function recordLineEvent(
        Ticket $ticket,
        TicketPlannedLine $line,
        SalesQuoteVersion $version,
        Model $record,
        User $actor,
    ): void {
        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor->id,
            'type' => 'accepted_quote_auto_processed',
            'message' => 'Accepted quote line processed automatically.',
            'after' => [
                'quote_version_id' => $version->id,
                'planned_line_id' => $line->id,
                'record_type' => $record->getMorphClass(),
                'record_id' => $record->getKey(),
            ],
        ]);
    }

    private function recordLineFailure(
        Ticket $ticket,
        TicketPlannedLine $line,
        SalesQuoteVersion $version,
        string $reason,
        User $actor,
    ): void {
        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $actor->id,
            'type' => 'accepted_quote_auto_process_failed',
            'message' => 'Accepted quote line needs manual delivery follow-up.',
            'after' => [
                'quote_version_id' => $version->id,
                'planned_line_id' => $line->id,
                'reason' => $reason,
            ],
        ]);
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
        }

        return $exception->getMessage() ?: 'Unknown processing error.';
    }
}
