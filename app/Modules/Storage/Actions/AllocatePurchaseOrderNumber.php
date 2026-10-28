<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** One transactional sequence for manual/API/import creation; existing identities are never renumbered. */
class AllocatePurchaseOrderNumber
{
    public const TABLE = 'storage_purchase_order_number_sequences';

    public const MIGRATION = '2026_10_28_040000_create_storage_purchase_order_number_sequences';

    /** Acquire the shared creation lock before import/master-data locks, including nested API finalization. */
    public function withinSequence(Closure $work): mixed
    {
        return DB::transaction(function () use ($work) {
            $this->lockForCreation();

            return $work();
        }, 3);
    }

    /** The caller already owns a transaction; observation or a missing row must never reset used numbering. */
    public function lockForCreation(): void
    {
        abort_unless(DB::transactionLevel() > 0, 503, 'Purchase order numbering requires an active transaction.');
        abort_unless(Schema::hasTable(self::TABLE), 503, 'Apply the purchase order numbering database update before creating orders.');
        $counter = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
        abort_unless($counter, 503, 'The purchase order numbering state requires operator reconciliation.');

        if ($counter->initialized_at === null) {
            $highest = (int) (DB::table('storage_purchase_orders')->max('id') ?? 0);
            // Include deleted/legacy rows. Read only identities, never supplier document bodies.
            foreach (DB::table('storage_purchase_orders')->select('po_number')->orderBy('id')->cursor() as $legacy) {
                $value = $this->boundedInteger((string) $legacy->po_number);
                if ($value !== null) {
                    $highest = max($highest, $value);
                }
            }
            DB::table(self::TABLE)->where('id', 1)->update(['last_number' => $highest, 'initialized_at' => now()]);
        }
    }

    public function handle(PurchaseOrder $purchaseOrder, ?string $requestedPoNumber = null): PurchaseOrder
    {
        return $this->withinSequence(function () use ($purchaseOrder, $requestedPoNumber): PurchaseOrder {
            $stored = PurchaseOrder::withTrashed()->whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if (! PurchaseOrder::isPendingNumber($stored->po_number)) {
                if (filled($requestedPoNumber) && trim($requestedPoNumber) !== (string) $stored->po_number) {
                    throw ValidationException::withMessages(['po_number' => 'The generated Nexum order number cannot be changed.']);
                }

                return $stored;
            }
            if (filled($requestedPoNumber) || ! $purchaseOrder->wasRecentlyCreated || $stored->trashed()
                || $purchaseOrder->getRawOriginal('po_number') !== $stored->po_number) {
                throw ValidationException::withMessages(['po_number' => 'Nexum assigns the order number only during the original creation.']);
            }

            for ($attempt = 0; $attempt < 1000; $attempt++) {
                $counter = DB::table(self::TABLE)->where('id', 1)->lockForUpdate()->first();
                $last = $this->boundedInteger((string) $counter->last_number);
                abort_unless($last !== null, 503, 'The purchase order numbering state is unavailable.');
                $last = max($last, (int) $purchaseOrder->id - 1);
                abort_if($last >= PHP_INT_MAX, 503, 'The purchase order number sequence needs operator review.');
                $next = $last + 1;
                DB::table(self::TABLE)->where('id', 1)->update(['last_number' => $next]);
                // Exact database comparison also respects the installed unique-index collation.
                if (DB::table('storage_purchase_orders')->where('po_number', (string) $next)->exists()) {
                    continue;
                }

                try {
                    // A savepoint confines a legacy writer's unique-index race before choosing the next number.
                    DB::transaction(fn () => $purchaseOrder->assignGeneratedNumber((string) $next));

                    return $purchaseOrder;
                } catch (QueryException $error) {
                    if (! $this->isNumberUniqueViolation($error)) {
                        throw $error;
                    }
                }
            }

            abort(503, 'The purchase order numbering state needs reconciliation before another creation.');
        });
    }

    private function boundedInteger(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }
        $value = ltrim($value, '0');
        if ($value === '') {
            return 0;
        }
        $maximum = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($maximum) || strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0) {
            return null;
        }

        return (int) $value;
    }

    private function isNumberUniqueViolation(QueryException $error): bool
    {
        return in_array((string) $error->getCode(), ['23000', '23505'], true)
            && str_contains(strtolower($error->getMessage()), 'po_number');
    }
}
