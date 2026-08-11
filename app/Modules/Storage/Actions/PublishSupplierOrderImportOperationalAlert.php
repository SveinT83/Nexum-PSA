<?php

namespace App\Modules\Storage\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Notifications\SupplierOrderImportDailyDigestNotification;
use App\Modules\Storage\Notifications\SupplierOrderImportExceptionNotification;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Throwable;

class PublishSupplierOrderImportOperationalAlert
{
    private const RECIPIENT_PERMISSION = 'storage.purchase_import_policy_manage';

    /**
     * Persist an operational condition and notify each eligible user once per occurrence.
     *
     * @param  array<string, mixed>  $alert
     */
    public function handle(array $alert, bool $notify = true): int
    {
        $identity = mb_substr((string) ($alert['identity'] ?? ''), 0, 1000);
        if ($identity === '') {
            throw new \InvalidArgumentException('Operational alerts require a stable identity.');
        }
        $dedupeKey = hash('sha256', $identity);
        $now = now();
        $alertId = DB::transaction(function () use ($alert, $dedupeKey, $now): int {
            $existing = DB::table('storage_purchase_order_import_operational_alerts')
                ->where('dedupe_key', $dedupeKey)
                ->lockForUpdate()
                ->first();
            $context = $this->sanitizeContext(is_array($alert['context'] ?? null) ? $alert['context'] : []);
            $values = [
                'alert_type' => mb_substr((string) ($alert['type'] ?? 'unknown'), 0, 255),
                'severity' => in_array($alert['severity'] ?? null, ['info', 'warning', 'critical'], true)
                    ? $alert['severity']
                    : 'warning',
                'import_id' => $alert['import_id'] ?? null,
                'profile_id' => $alert['profile_id'] ?? null,
                'reason_code' => filled($alert['reason_code'] ?? null)
                    ? mb_substr((string) $alert['reason_code'], 0, 255)
                    : null,
                'title' => mb_substr((string) ($alert['title'] ?? 'Supplier-order import exception'), 0, 255),
                'summary' => mb_substr((string) ($alert['summary'] ?? 'Review import operations.'), 0, 2000),
                'context' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'last_detected_at' => $now,
                'resolved_at' => null,
                'updated_at' => $now,
            ];

            if (! $existing) {
                return (int) DB::table('storage_purchase_order_import_operational_alerts')->insertGetId(
                    $values + [
                        'dedupe_key' => $dedupeKey,
                        'occurrence' => 1,
                        'first_detected_at' => $now,
                        'created_at' => $now,
                    ],
                );
            }

            if ($existing->resolved_at !== null) {
                $values['occurrence'] = ((int) $existing->occurrence) + 1;
                $values['first_detected_at'] = $now;
            }
            DB::table('storage_purchase_order_import_operational_alerts')
                ->where('id', $existing->id)
                ->update($values);

            return (int) $existing->id;
        });

        if ($notify) {
            $row = DB::table('storage_purchase_order_import_operational_alerts')->find($alertId);
            if ($row) {
                $this->deliver($alertId, $this->notificationFromAlert($row));
            }
        }

        return $alertId;
    }

    public function deliver(int $alertId, LaravelNotification $notification): void
    {
        $alert = DB::table('storage_purchase_order_import_operational_alerts')->find($alertId);
        if (! $alert) {
            return;
        }

        $users = User::permission(self::RECIPIENT_PERMISSION)
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();
        $delivered = false;
        foreach ($users as $user) {
            $delivery = DB::table('storage_purchase_order_import_alert_deliveries')
                ->where('alert_id', $alertId)
                ->where('occurrence', $alert->occurrence)
                ->where('user_id', $user->id)
                ->first();
            if ($delivery && in_array($delivery->status, ['delivered', 'skipped'], true)) {
                continue;
            }
            if ($delivery?->delivery_started_at && ! $delivery->failed_at
                && CarbonImmutable::parse($delivery->delivery_started_at)->diffInMinutes(now()) < 15) {
                continue;
            }
            if ($delivery?->failed_at && CarbonImmutable::parse($delivery->failed_at)->diffInMinutes(now()) < 15) {
                continue;
            }

            $deliveryId = $delivery?->id ?: DB::table('storage_purchase_order_import_alert_deliveries')->insertGetId([
                'alert_id' => $alertId,
                'occurrence' => $alert->occurrence,
                'user_id' => $user->id,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            try {
                $channels = $notification->via($user);
                DB::table('storage_purchase_order_import_alert_deliveries')
                    ->where('id', $deliveryId)
                    ->update([
                        'status' => $channels === [] ? 'skipped' : 'sending',
                        'channels' => json_encode(array_values($channels), JSON_THROW_ON_ERROR),
                        'delivery_started_at' => now(),
                        'failed_at' => null,
                        'failure_class' => null,
                        'updated_at' => now(),
                    ]);
                if ($channels !== []) {
                    Notification::sendNow($user, $notification, $channels);
                    $delivered = true;
                }
                DB::table('storage_purchase_order_import_alert_deliveries')
                    ->where('id', $deliveryId)
                    ->update([
                        'status' => $channels === [] ? 'skipped' : 'delivered',
                        'delivered_at' => now(),
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $exception) {
                DB::table('storage_purchase_order_import_alert_deliveries')
                    ->where('id', $deliveryId)
                    ->update([
                        'status' => 'failed',
                        'failed_at' => now(),
                        'failure_class' => mb_substr($exception::class, 0, 255),
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($delivered) {
            DB::table('storage_purchase_order_import_operational_alerts')
                ->where('id', $alertId)
                ->update(['last_notified_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Retry failed per-user deliveries after a cooling-off period without duplicating successes.
     */
    public function retryFailedDeliveries(int $limit = 50): int
    {
        $alertIds = DB::table('storage_purchase_order_import_alert_deliveries as deliveries')
            ->join(
                'storage_purchase_order_import_operational_alerts as alerts',
                'alerts.id',
                '=',
                'deliveries.alert_id',
            )
            ->where('deliveries.status', 'failed')
            ->where('deliveries.failed_at', '<=', now()->subMinutes(15))
            ->whereNull('alerts.resolved_at')
            ->orderBy('alerts.id')
            ->limit(max(1, min(250, $limit)))
            ->distinct()
            ->pluck('alerts.id');

        foreach ($alertIds as $alertId) {
            $alert = DB::table('storage_purchase_order_import_operational_alerts')->find($alertId);
            if ($alert) {
                $this->deliver((int) $alertId, $this->notificationFromAlert($alert));
            }
        }

        return $alertIds->count();
    }

    /**
     * Resolve conditions absent from the latest health pass. Reappearance creates a new occurrence.
     *
     * @param  list<string>  $types
     * @param  list<string>  $activeIdentities
     */
    public function resolveMissing(array $types, array $activeIdentities): void
    {
        $query = DB::table('storage_purchase_order_import_operational_alerts')
            ->whereIn('alert_type', $types)
            ->whereNull('resolved_at');
        $activeKeys = array_map(fn (string $identity): string => hash('sha256', $identity), $activeIdentities);
        if ($activeKeys !== []) {
            $query->whereNotIn('dedupe_key', $activeKeys);
        }
        $resolvedIds = (clone $query)->pluck('id');
        $query->update(['resolved_at' => now(), 'updated_at' => now()]);
        if ($resolvedIds->isNotEmpty()) {
            DB::table('storage_purchase_order_import_alert_deliveries')
                ->whereIn('alert_id', $resolvedIds)
                ->whereIn('status', ['pending', 'sending', 'failed'])
                ->update(['status' => 'resolved', 'updated_at' => now()]);
        }
    }

    private function notificationFromAlert(object $alert): LaravelNotification
    {
        $context = is_string($alert->context ?? null) ? json_decode($alert->context, true) : [];
        $context = is_array($context) ? $context : [];
        if ($alert->alert_type === 'daily_digest') {
            return new SupplierOrderImportDailyDigestNotification(
                alertId: (int) $alert->id,
                period: (string) ($context['period'] ?? ''),
                total: (int) ($context['total'] ?? 0),
                statusCounts: is_array($context['status_counts'] ?? null) ? $context['status_counts'] : [],
                reasonCounts: is_array($context['reason_counts'] ?? null) ? $context['reason_counts'] : [],
            );
        }

        return new SupplierOrderImportExceptionNotification(
            alertId: (int) $alert->id,
            alertType: (string) $alert->alert_type,
            severity: (string) $alert->severity,
            title: (string) $alert->title,
            summary: (string) $alert->summary,
            context: $context,
        );
    }

    /** @param array<array-key, mixed> $context @return array<array-key, mixed> */
    private function sanitizeContext(array $context, int $depth = 0): array
    {
        if ($depth >= 4) {
            return [];
        }
        $blocked = ['body', 'body_html', 'body_text', 'headers', 'prompt', 'response', 'raw', 'secret'];
        $clean = [];
        foreach (array_slice($context, 0, 50, true) as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), $blocked, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeContext($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = mb_substr($value, 0, 500);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
