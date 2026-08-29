<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->string('action_key', 191)->nullable()->after('signal_rule_id');
        });

        $deliveriesByActionKey = [];
        DB::table('signal_webhook_deliveries')
            ->select(['id', 'status', 'payload'])
            ->orderBy('id')
            ->chunkById(500, function ($deliveries) use (&$deliveriesByActionKey): void {
                foreach ($deliveries as $delivery) {
                    $payload = json_decode((string) $delivery->payload, true);
                    $actionKey = is_array($payload)
                        ? data_get($payload, 'signal_action_key')
                        : null;

                    if (! is_string($actionKey)
                        || $actionKey === ''
                        || strlen($actionKey) > 191) {
                        continue;
                    }

                    $deliveriesByActionKey[$actionKey][] = [
                        'id' => (int) $delivery->id,
                        'status' => (string) $delivery->status,
                    ];
                }
            });

        $statusPriority = static fn (string $status): int => match ($status) {
            'delivered' => 0,
            'running' => 1,
            'failed' => 2,
            'unresolved' => 3,
            'pending' => 4,
            default => 5,
        };
        $timestamp = now();

        foreach ($deliveriesByActionKey as $actionKey => $deliveries) {
            usort($deliveries, static function (array $left, array $right) use ($statusPriority): int {
                return [$statusPriority($left['status']), $left['id']]
                    <=> [$statusPriority($right['status']), $right['id']];
            });

            $canonical = array_shift($deliveries);
            DB::table('signal_webhook_deliveries')
                ->where('id', $canonical['id'])
                ->update(['action_key' => $actionKey]);

            foreach ($deliveries as $duplicate) {
                if (! in_array($duplicate['status'], ['pending', 'running'], true)) {
                    continue;
                }

                DB::table('signal_webhook_deliveries')
                    ->where('id', $duplicate['id'])
                    ->whereIn('status', ['pending', 'running'])
                    ->update([
                        'status' => 'unresolved',
                        'claim_token' => null,
                        'last_error' => 'Legacy duplicate Signal webhook action requires reconciliation.',
                        'completed_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
            }
        }

        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->unique('action_key');
        });
    }

    public function down(): void
    {
        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['action_key']);
            $table->dropColumn('action_key');
        });
    }
};
