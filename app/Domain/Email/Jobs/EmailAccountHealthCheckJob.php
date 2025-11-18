<?php

namespace App\Domain\Email\Jobs;

use App\Domain\Email\Models\EmailAccount;
use App\Domain\Email\Models\EmailHealthCheck;
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

    public function handle(): void
    {
        $account = EmailAccount::find($this->accountId);
        if (!$account) {
            return;
        }

        $imapStatus = 'OK'; // TODO: real check
        $smtpStatus = 'OK'; // TODO: real check
        $errorCode = null;
        $errorMessage = null;
        $durations = [
            'imap_ms' => null,
            'smtp_ms' => null,
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

        $account->last_test_at = now();
        $account->last_test_result = ($imapStatus === 'OK' && $smtpStatus === 'OK') ? 'OK' : 'Error';
        if ($errorMessage) {
            $account->last_error_message = $errorMessage;
            $account->last_error_code = $errorCode;
        }
        $account->save();
    }
}
