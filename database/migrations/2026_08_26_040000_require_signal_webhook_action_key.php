<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $statusPriority = static fn (string $status): int => match ($status) {
            'delivered' => 0,
            'running' => 1,
            'failed' => 2,
            'unresolved' => 3,
            'pending' => 4,
            default => 5,
        };

        DB::table('signal_webhook_deliveries')
            ->whereNull('action_key')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(500, function ($deliveries) use ($statusPriority): void {
                foreach ($deliveries as $delivery) {
                    DB::transaction(function () use ($delivery, $statusPriority): void {
                        $current = DB::table('signal_webhook_deliveries')
                            ->where('id', $delivery->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $current || $current->action_key !== null) {
                            return;
                        }

                        $payload = json_decode((string) $current->payload, true);
                        $payloadActionKey = is_array($payload)
                            ? data_get($payload, 'signal_action_key')
                            : null;
                        $validPayloadKey = is_string($payloadActionKey)
                            && $payloadActionKey !== ''
                            && strlen($payloadActionKey) <= 191;
                        $legacyKey = 'legacy:delivery:'.$current->id;

                        if (! $validPayloadKey) {
                            DB::table('signal_webhook_deliveries')
                                ->where('id', $current->id)
                                ->update(['action_key' => $legacyKey]);

                            return;
                        }

                        $canonical = DB::table('signal_webhook_deliveries')
                            ->where('action_key', $payloadActionKey)
                            ->lockForUpdate()
                            ->first();

                        if (! $canonical) {
                            DB::table('signal_webhook_deliveries')
                                ->where('id', $current->id)
                                ->update(['action_key' => $payloadActionKey]);

                            return;
                        }

                        if ($statusPriority((string) $current->status) < $statusPriority((string) $canonical->status)) {
                            $canonicalUpdates = ['action_key' => 'legacy:delivery:'.$canonical->id];
                            if (in_array($canonical->status, ['pending', 'running'], true)) {
                                $canonicalUpdates = array_merge($canonicalUpdates, [
                                    'status' => 'unresolved',
                                    'claim_token' => null,
                                    'last_error' => 'Legacy duplicate Signal webhook action requires reconciliation.',
                                    'completed_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }

                            DB::table('signal_webhook_deliveries')
                                ->where('id', $canonical->id)
                                ->update($canonicalUpdates);
                            DB::table('signal_webhook_deliveries')
                                ->where('id', $current->id)
                                ->update(['action_key' => $payloadActionKey]);

                            return;
                        }

                        $currentUpdates = ['action_key' => $legacyKey];
                        if (in_array($current->status, ['pending', 'running'], true)) {
                            $currentUpdates = array_merge($currentUpdates, [
                                'status' => 'unresolved',
                                'claim_token' => null,
                                'last_error' => 'Legacy duplicate Signal webhook action requires reconciliation.',
                                'completed_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }

                        DB::table('signal_webhook_deliveries')
                            ->where('id', $current->id)
                            ->update($currentUpdates);
                    });
                }
            });

        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->string('action_key', 191)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('signal_webhook_deliveries', function (Blueprint $table): void {
            $table->string('action_key', 191)->nullable()->change();
        });
    }
};
