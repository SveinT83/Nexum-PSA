<?php

namespace App\Modules\Commercial\Services;

use App\Mail\ContractLinkSent;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\CaptureContractTermVersions;
use App\Modules\Commercial\Actions\ContractLifecycleWorkflow;
use App\Modules\Commercial\Models\CommercialDeliveryAttempt;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\CommercialRevision;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\System\Support\CompanyProfileSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable explicit communication: reviewed frozen content, safe retry and honest delivery outcomes. */
class CommercialDelivery
{
    public function revision(Contracts $contract): string
    {
        return hash('sha256', json_encode([CommercialRevision::of($contract), app(CompanyProfileSettings::class)->get(), app(EmailProviderBindingSnapshot::class)->captureScope('system')], JSON_THROW_ON_ERROR));
    }

    public function preview(Contracts $contract, User $actor, string $kind, ?string $cc = null): CommercialDeliveryAttempt
    {
        return DB::transaction(function () use ($contract, $actor, $kind, $cc) {
            $contract = Contracts::query()->with('client')->lockForUpdate()->findOrFail($contract->id);
            abort_unless(in_array($kind, ['quote', 'contract', 'resend'], true), 422);
            if ($kind === 'resend') {
                abort_unless(in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true) && filled($contract->secure_token), 409, 'Only an existing captured sent document with its original link may be resent.');
            } else {
                abort_unless($contract->isEditable() && $contract->isReady(), 409, 'The contract is not ready to send.');
                app(ContractDocumentReadiness::class)->assertReadyForCapture($contract, app(CompanyProfileSettings::class)->get());
                app(ContractTermSnapshotReadiness::class)->assertCurrent($contract);
                // This explicit POST stages the exact legal versions reviewed for delivery, without sending or accepting.
                app(CaptureContractTermVersions::class)->replace($contract);
            }
            $contract->refresh()->load('client');
            $to = trim((string) $contract->client?->billing_email);
            abort_if($to !== '' && ! filter_var($to, FILTER_VALIDATE_EMAIL), 409, 'The billing recipient is invalid.');
            abort_if($kind === 'resend' && $to === '', 409, 'A resend requires a valid billing recipient.');
            $binding = app(EmailProviderBindingSnapshot::class)->captureScope('system');
            if ($to !== '') {
                try {
                    app(EmailProviderBindingSnapshot::class)->resolveScope('system', $binding['account_id'], $binding['provider_binding_version']);
                } catch (\Throwable) {
                    abort(409, 'No ready system Email provider is available.');
                }
            }
            $type = $kind === 'resend' ? ($contract->approval_status === 'sent_quote' ? 'quote' : 'contract') : $kind;
            $token = $kind === 'resend' ? $contract->secure_token : Str::random(64);
            $frozen = clone $contract;
            $frozen->secure_token = $token;
            $mailable = new ContractLinkSent($frozen, $type);
            $html = $mailable->render();
            $documents = app(ContractCustomerDocument::class);
            $document = $kind === 'resend' ? $documents->resolve($contract) : $documents->build($contract, null, $type === 'quote' ? 'sent_quote' : 'sent_contract');
            $documents->assertSupportedSnapshot($document);
            $payload = ['to' => $to, 'to_name' => $contract->client?->name, 'cc' => $cc, 'subject' => (string) $mailable->envelope()->subject, 'html' => $html, 'text' => BodyNormalizer::toText($html), 'binding' => $binding, 'token' => $token, 'document' => $document, 'type' => $type];
            $revision = $this->revision($contract);

            return CommercialDeliveryAttempt::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'contract_id' => $contract->id, 'kind' => $kind, 'state' => 'preview', 'revision' => $revision, 'preview_hash' => hash('sha256', json_encode([$payload, $revision], JSON_THROW_ON_ERROR)), 'payload' => $payload, 'expires_at' => now()->addMinutes(15)]);
        });
    }

    public function submit(CommercialDeliveryAttempt $attempt, Contracts $contract, User $actor, string $hash): CommercialDeliveryAttempt
    {
        return DB::transaction(function () use ($attempt, $contract, $actor, $hash) {
            $contract = Contracts::query()->with('client')->lockForUpdate()->findOrFail($contract->id);
            $attempt = CommercialDeliveryAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless((int) $attempt->actor_id === (int) $actor->id && (int) $attempt->contract_id === (int) $contract->id, 404);
            abort_unless($attempt->state === 'preview' && $attempt->expires_at->isFuture(), 409, 'This preview expired or was already submitted.');
            abort_unless(hash_equals($attempt->preview_hash, $hash) && hash_equals($attempt->revision, $this->revision($contract)), 409, 'Document, recipient or provider changed; review a new preview.');
            $payload = $attempt->payload;
            if ($attempt->kind === 'resend') {
                abort_unless(in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true) && filled($contract->secure_token) && hash_equals($contract->secure_token, $payload['token']), 409, 'The original sent capability changed.');
                app(ContractCustomerDocument::class)->resolve($contract);
                $contract->forceFill(['cc_email' => $payload['cc']])->save();
            } else {
                $result = app(ContractLifecycleWorkflow::class)->transitionForSending($contract, $payload['type'] === 'quote' ? 'sent_quote' : 'sent_contract', $payload['cc'], $payload['document'], $payload['token']);
                abort_if(isset($result['error']), 409, $result['error'] ?? 'Contract transition refused.');
                $contract = $result['contract'];
                DB::afterCommit(function () use ($contract) {
                    app(\App\Modules\Notification\Actions\SendCustomerPortalNotification::class)->handle(type: 'portal_contract_sent', clientId: (int) $contract->client_id, siteId: null, title: $contract->approval_status === 'sent_quote' ? 'New contract quote available' : 'New contract available', body: $contract->description, url: route('customer-portal.contracts.show', $contract), sourceType: Contracts::class, sourceId: $contract->id, metadata: ['contract_id' => $contract->id, 'approval_status' => $contract->approval_status]);
                });
            }
            $payload['snapshot_hash'] = hash('sha256', json_encode($contract->fresh()->customer_document_snapshot, JSON_THROW_ON_ERROR));
            $attempt->forceFill(['payload' => $payload, 'state' => $payload['to'] === '' ? 'no_recipient' : 'queued'])->save();
            if ($attempt->state === 'queued') {
                $this->dispatch($attempt);
            }

            return $attempt->refresh();
        });
    }

    public function dispatch(CommercialDeliveryAttempt $attempt): void
    {
        abort_unless($attempt->state === 'queued' && ! $attempt->started_at, 409, 'Only a never-started queued attempt may be dispatched.');
        dispatch((new \App\Modules\Commercial\Jobs\SendCommercialDelivery($attempt->id))->afterCommit());
    }

    public function browser(Contracts $contract, User $actor, string $kind, ?string $cc = null): CommercialDeliveryAttempt
    {
        return DB::transaction(function () use ($contract, $actor, $kind, $cc) {
            $preview = $this->preview($contract, $actor, $kind, $cc);

            return $this->submit($preview, $contract, $actor, $preview->preview_hash);
        });
    }

    public function projection(CommercialDeliveryAttempt $attempt, bool $preview = false): array
    {
        $out = $attempt->only(['id', 'contract_id', 'kind', 'state', 'revision', 'preview_hash', 'expires_at', 'started_at', 'finished_at', 'message_id', 'error_code', 'created_at', 'updated_at']);
        $out['inbox_delivery_confirmed'] = false;
        $out['provider_submission_confirmed'] = $attempt->state === 'smtp_accepted';
        $out['recovery_allowed'] = $attempt->state === 'queued' && ! $attempt->started_at;
        $out['needs_reconciliation'] = $attempt->state === 'unknown' || ($attempt->state === 'processing' && $attempt->started_at && $attempt->started_at->lt(now()->subMinutes(3)));

        if ($preview) {
            $p = $attempt->payload;
            $out['review'] = ['to' => $p['to'], 'cc' => $p['cc'], 'subject' => $p['subject'], 'html' => $p['html'], 'customer_document' => $p['document']];
        }

        return $out;
    }
}
