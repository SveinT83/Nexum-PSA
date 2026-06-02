<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailHealthCheck;
use App\Modules\Email\Services\EmailTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EmailAccountHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public function __construct(public int $accountId) {}

    public function handle(EmailTestService $tester): void
    {
        $account = EmailAccount::find($this->accountId);
        if (!$account) {
            return;
        }

        $result = $tester->run($account);
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
}
