<?php

namespace App\Modules\Commercial\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\SmtpAccountMailer;

/** Narrow outbound boundary: no fallback account and no mutable message reconstruction. */
class CommercialDeliveryTransport
{
    public function ready(array $binding): EmailAccount
    {
        return app(EmailProviderBindingSnapshot::class)->resolveScope('system', $binding['account_id'], $binding['provider_binding_version']);
    }

    public function send(EmailAccount $account, array $payload): string
    {
        return app(SmtpAccountMailer::class)->send($account, $payload['to'], $payload['to_name'], $payload['subject'], $payload['html'], $payload['text'], [], $payload['cc'] ? [['email' => $payload['cc'], 'name' => '']] : [], ['provider_binding_version' => $payload['binding']['provider_binding_version']]);
    }
}
