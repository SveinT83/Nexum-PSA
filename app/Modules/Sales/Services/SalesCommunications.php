<?php

namespace App\Modules\Sales\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Sales\Actions\EvaluateSalesQuoteApproval;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesDeliveryAttempt;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Support\SalesApiAccess;
use App\Modules\Sales\Support\SalesRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable preview/outbox state; no ambiguous SMTP attempt is automatically retried. */
class SalesCommunications
{
    public function preview(User $actor, string $kind, SalesOpportunity $opportunity, ?SalesQuoteVersion $version = null, array $input = []): SalesDeliveryAttempt
    {
        $opportunity->loadMissing(['client', 'currentQuoteVersion.quote']);
        if ($kind === 'quote') {
            $content = app(SalesEmailContent::class)->quote($version);
        } else {
            $request = Request::create('/', 'POST', $input);
            $request->setUserResolver(fn () => $actor);
            $attributes = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->activityData($request, $opportunity);
            $activity = new SalesActivity($attributes);
            $activity->setRelation('opportunity', $opportunity);
            $activity->setRelation('actor', $actor);
            $content = $kind === 'internal' ? app(SalesEmailContent::class)->internal($activity) : app(SalesEmailContent::class)->activity($activity);
        }
        $binding = app(EmailProviderBindingSnapshot::class)->captureScope('sales');
        $payload = $content + ['input' => $input, 'account_id' => $binding['account_id'], 'provider_binding_version' => $binding['provider_binding_version']];
        if (! $binding['account_id'] && empty($payload['error_code'])) {
            $payload['error_code'] = 'no_account';
        }
        $revision = $this->revision($kind, $opportunity, $version, $input);

        return SalesDeliveryAttempt::query()->create([
            'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'opportunity_id' => $opportunity->id,
            'quote_version_id' => $version?->id, 'kind' => $kind, 'state' => 'preview',
            'revision' => $revision, 'preview_hash' => hash('sha256', json_encode([$payload, $revision], JSON_THROW_ON_ERROR)),
            'payload' => $payload, 'expires_at' => now()->addMinutes(15),
        ]);
    }

    public function revision(string $kind, SalesOpportunity $opportunity, ?SalesQuoteVersion $version, array $input): string
    {
        $opportunity = $opportunity->fresh(['client', 'primaryContact', 'currentQuoteVersion']);
        $templateKey = match ($kind) {
            'quote' => 'sales_quote_send', 'internal' => 'sales_internal_note', default => 'sales_activity_email'
        };
        $contact = ! empty($input['recipient_contact_id']) ? \App\Models\Clients\ClientUser::find($input['recipient_contact_id']) : $opportunity->primaryContact;
        $notify = ! empty($input['notify_user_id']) ? User::find($input['notify_user_id']) : null;

        return hash('sha256', json_encode([
            SalesRevision::of($version ?? $opportunity), $opportunity->only(['primary_contact_id', 'client_id', 'title', 'current_quote_version_id']),
            $contact?->only(['id', 'name', 'email', 'active', 'client_site_id']), $notify?->only(['id', 'email', 'name', 'status']),
            app(EmailProviderBindingSnapshot::class)->captureScope('sales'),
            EmailTemplate::query()->where('scope', 'sales')->where('key', $templateKey)->where('is_active', true)->first()?->getRawOriginal(),
            \App\Modules\Sales\Models\SalesSetting::get('cpq_approval_policy', EvaluateSalesQuoteApproval::DEFAULT_POLICY),
        ], JSON_THROW_ON_ERROR));
    }

    public function assertPreview(string $id, User $actor, string $kind, SalesOpportunity $opportunity, ?SalesQuoteVersion $version = null, ?string $hash = null): SalesDeliveryAttempt
    {
        $attempt = SalesDeliveryAttempt::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $attempt->actor_id === (int) $actor->id && $attempt->kind === $kind && (int) $attempt->opportunity_id === (int) $opportunity->id && (int) $attempt->quote_version_id === (int) $version?->id, 404);
        abort_unless($attempt->state === 'preview' && $attempt->expires_at->isFuture(), 409, 'The preview expired or was already submitted.');
        abort_if($hash !== null && ! hash_equals($attempt->preview_hash, $hash), 409, 'The reviewed preview changed.');
        abort_unless(hash_equals($attempt->revision, $this->revision($kind, $opportunity, $version, $attempt->payload['input'] ?? [])), 409, 'Content, recipient, approval policy or provider changed. Review a new preview.');

        return $attempt;
    }

    public function queue(SalesDeliveryAttempt $attempt, ?SalesActivity $activity = null): SalesDeliveryAttempt
    {
        $attempt->refresh();
        abort_unless($attempt->state === 'preview', 409, 'The preview was already submitted.');
        $attempt->forceFill(['activity_id' => $activity?->id, 'state' => empty($attempt->payload['to_email']) ? 'no_recipient' : 'queued'])->save();
        if ($activity) {
            $activity->forceFill(['metadata' => array_merge($activity->metadata ?? [], ['delivery_attempt_id' => $attempt->id])])->save();
        }
        if ($attempt->state === 'queued') {
            $this->dispatch($attempt);
        }

        return $attempt;
    }

    public function dispatch(SalesDeliveryAttempt $attempt): void
    {
        abort_unless($attempt->state === 'queued' && ! $attempt->started_at, 409, 'Only a never-started queued attempt can be dispatched.');
        $job = match ($attempt->kind) {
            'quote' => new \App\Modules\Sales\Jobs\SendSalesQuoteEmail($attempt->quote_version_id, $attempt->id),
            'internal' => new \App\Modules\Sales\Jobs\SendSalesInternalNotificationEmail($attempt->activity_id, $attempt->id),
            default => new \App\Modules\Sales\Jobs\SendSalesActivityEmail($attempt->activity_id, $attempt->id),
        };
        dispatch($job->afterCommit());
    }

    public function project(SalesDeliveryAttempt $attempt, bool $content = false): array
    {
        $data = $attempt->only(['id', 'kind', 'opportunity_id', 'quote_version_id', 'activity_id', 'state', 'preview_hash', 'expires_at', 'started_at', 'finished_at', 'message_id', 'error_code']);
        $data['inbox_receipt_verified'] = false;
        if ($content && $attempt->state === 'preview') {
            $data['preview'] = \Illuminate\Support\Arr::only($attempt->payload, ['to_email', 'to_name', 'cc', 'subject', 'html', 'text', 'error_code']);
            $data['ready'] = empty($attempt->payload['error_code']) && $attempt->expires_at->isFuture();
        }

        return $data;
    }

    public function deliver(string $id): void
    {
        $attempt = DB::transaction(function () use ($id) {
            $row = SalesDeliveryAttempt::query()->whereKey($id)->lockForUpdate()->first();
            if (! $row || $row->state !== 'queued' || $row->started_at) {
                return null;
            }
            $row->forceFill(['state' => 'processing', 'started_at' => now()])->save();

            return $row;
        });
        if (! $attempt) {
            return;
        }
        $sending = false;
        try {
            $actor = User::find($attempt->actor_id);
            $request = Request::create('/');
            $request->setUserResolver(fn () => $actor);
            SalesApiAccess::authorize($request, $attempt->kind === 'quote' ? 'sales.quote_manage' : 'sales.view');
            $payload = $attempt->payload;
            if (! empty($payload['error_code'])) {
                $attempt->forceFill(['state' => 'failed', 'error_code' => $payload['error_code'], 'finished_at' => now()])->save();

                return;
            }
            $account = app(EmailProviderBindingSnapshot::class)->resolveScope('sales', $payload['account_id'], $payload['provider_binding_version']);
            if ($attempt->kind === 'quote') {
                $version = SalesQuoteVersion::findOrFail($attempt->quote_version_id);
                abort_unless($version->status === 'sent', 409, 'Quote state changed before delivery.');
                $policy = app(EvaluateSalesQuoteApproval::class)->handle($version);
                abort_if($policy['required'] && $version->approval_status !== 'approved', 409, 'Quote approval changed before delivery.');
            }
            // From here an exception may mean the provider accepted SMTP but the response was lost.
            $sending = true;
            $messageId = app(SmtpAccountMailer::class)->send($account, $payload['to_email'], $payload['to_name'] ?? '', $payload['subject'], $payload['html'], $payload['text'], [], $payload['cc'] ?? [], ['provider_binding_version' => $payload['provider_binding_version']]);
            $attempt->forceFill(['state' => 'smtp_accepted', 'message_id' => $messageId, 'finished_at' => now(), 'error_code' => null])->save();
            EmailLog::query()->create(['direction' => 'outbound', 'account_id' => $account->id, 'scope' => 'sales', 'level' => 'info',
                'code' => $attempt->kind === 'quote' ? 'SALES_QUOTE_SENT' : ($attempt->kind === 'internal' ? 'SALES_INTERNAL_NOTIFY_SENT' : 'SALES_EMAIL_SENT'),
                'message' => 'Sales SMTP submission accepted; inbox receipt is unverified.', 'context_json' => ['delivery_attempt_id' => $attempt->id, 'sales_quote_version_id' => $attempt->quote_version_id, 'sales_activity_id' => $attempt->activity_id, 'opportunity_id' => $attempt->opportunity_id], 'rfc_message_id' => $messageId]);
        } catch (\Throwable $error) {
            // Never log provider exceptions or credentials. An explicit new intent is needed after reconciliation.
            $attempt->forceFill(['state' => $sending ? 'unknown' : 'failed', 'error_code' => $sending ? 'smtp_outcome_unknown' : 'preflight_failed', 'finished_at' => now()])->save();
        }
    }
}
