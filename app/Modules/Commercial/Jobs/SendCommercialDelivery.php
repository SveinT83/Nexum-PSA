<?php

namespace App\Modules\Commercial\Jobs;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Models\Core\User;
use App\Modules\Commercial\Models\CommercialDeliveryAttempt;
use App\Modules\Commercial\Models\Contracts\Contracts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Claim once before external I/O. An ambiguous SMTP outcome must never be retried blindly. */
class SendCommercialDelivery implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $attemptId) {}

    public function handle(): void
    {
        $attempt = DB::transaction(function () {
            $row = CommercialDeliveryAttempt::query()->lockForUpdate()->find($this->attemptId);
            if (! $row || $row->state !== 'queued' || $row->started_at) {
                return null;
            }
            $row->forceFill(['state' => 'processing', 'started_at' => now()])->save();

            return $row;
        });
        if (! $attempt) {
            return;
        }
        try {
            $actor = User::query()->find($attempt->actor_id);
            abort_unless($actor && $actor->isActive() && app(EnforceTechRoutePermission::class)->allowsActor($actor, 'commercial.contract_manage'), 403);
            $contract = Contracts::query()->with('client')->findOrFail($attempt->contract_id);
            $p = $attempt->payload;
            abort_unless(in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true)
                && hash_equals((string) $contract->secure_token, $p['token'])
                && hash_equals(hash('sha256', json_encode($contract->customer_document_snapshot, JSON_THROW_ON_ERROR)), $p['snapshot_hash'])
                && trim((string) $contract->client?->billing_email) === $p['to']
                && $contract->cc_email === $p['cc'], 409);
            $account = app(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class)->ready($p['binding']);
        } catch (\Throwable) {
            $attempt->forceFill(['state' => 'review_required', 'error_code' => 'preflight_changed', 'finished_at' => now()])->save();

            return;
        }
        try {
            $messageId = app(\App\Modules\Commercial\Services\CommercialDeliveryTransport::class)->send($account, $p);
            $attempt->forceFill(['state' => 'smtp_accepted', 'message_id' => $messageId, 'finished_at' => now()])->save();
        } catch (\Throwable) {
            $attempt->forceFill(['state' => 'unknown', 'error_code' => 'provider_outcome_unconfirmed', 'finished_at' => now()])->save();
        }
    }

    public function failed(?\Throwable $error): void
    {
        CommercialDeliveryAttempt::query()->whereKey($this->attemptId)->where('state', 'processing')
            ->update(['state' => 'unknown', 'error_code' => 'worker_outcome_unconfirmed', 'finished_at' => now()]);
    }
}
