<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailHealthCheck;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmailAccountHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Must exceed the maximum 120-second provider deadline, five-second
    // cleanup grace, database persistence, and worker shutdown margin.
    public int $timeout = 240;

    public ?int $providerBindingVersion = null;

    public function __construct(public int $accountId)
    {
        $account = EmailAccount::query()->find($this->accountId);
        $this->providerBindingVersion = $account
            ? app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            : null;
    }

    public function handle(EmailTestService $tester): void
    {
        $account = EmailAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        if (! $this->providerBindingVersion || $this->providerBindingVersion < 1) {
            $this->recordBlocked($account, 'PROVIDER_BINDING_SNAPSHOT_MISSING');

            return;
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            !== $this->providerBindingVersion) {
            $this->recordBlocked($account, 'PROVIDER_BINDING_STALE');

            return;
        }

        $result = $tester->run($account, $this->providerBindingVersion);
        $imapStatus = $result->imap_ok ? 'OK' : 'Error';
        $smtpStatus = $result->smtp_ok ? 'OK' : 'Error';
        $errorCode = $result->imap_error_code ?: $result->smtp_error_code;
        $errorMessage = collect([
            $result->imap_error_message ? 'IMAP: '.$result->imap_error_message : null,
            $result->smtp_error_message ? 'SMTP: '.$result->smtp_error_message : null,
        ])->filter()->implode(' | ') ?: null;
        $durations = [
            'imap_ms' => $result->imap_ms,
            'smtp_ms' => $result->smtp_ms,
        ];

        EmailHealthCheck::create([
            'account_id' => $account->id,
            'checked_at' => now(),
            'imap_status' => $imapStatus,
            'smtp_status' => $smtpStatus,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'durations_json' => $durations,
        ]);
    }

    private function recordBlocked(EmailAccount $account, string $code): void
    {
        EmailHealthCheck::query()->create([
            'account_id' => $account->id,
            'checked_at' => now(),
            'imap_status' => 'Blocked',
            'smtp_status' => 'Blocked',
            'error_code' => $code,
            'error_message' => 'The queued provider check was superseded before any network operation.',
            'durations_json' => ['imap_ms' => 0, 'smtp_ms' => 0],
        ]);
    }
}
