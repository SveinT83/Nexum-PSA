<?php

namespace App\Domain\Email\Services;

use App\Domain\Email\Models\EmailAccount;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Webklex\IMAP\Facades\Client as ImapClientFacade;

class EmailTestService
{
    public function run(EmailAccount $account): EmailTestResult
    {
        $res = new EmailTestResult();

        // IMAP test
        $t0 = microtime(true);
        try {
            // Use Webklex IMAP Facade to create a client across versions
            $client = ImapClientFacade::make([
                'host'          => $account->imap_host,
                'port'          => (int)$account->imap_port,
                'encryption'    => $this->mapImapEncryption($account->imap_encryption),
                'validate_cert' => true,
                'username'      => $account->imap_username,
                'password'      => Crypt::decryptString($account->imap_secret),
                'protocol'      => 'imap',
                'timeout'       => 20,
            ]);
            $client->connect();
            // attempt folder access
            $folders = $client->getFolders();
            $inbox = collect($folders)->first(fn($f) => strtoupper($f->name) === 'INBOX') ?? $folders[0] ?? null;
            if ($inbox) {
                $inbox->examine();
            }
            $client->disconnect();
            $res->imap_ok = true;
        } catch (\Throwable $e) {
            [$code, $msg] = $this->imapErrorClassify($e);
            $res->imap_error_code = $code;
            $res->imap_error_message = $msg;
            Log::warning('IMAP test failed', ['account_id' => $account->id, 'code' => $code, 'err' => $e->getMessage()]);
        } finally {
            $res->imap_ms = (microtime(true) - $t0) * 1000.0;
        }

        // SMTP test
        $t1 = microtime(true);
        try {
            [$sslFlag, $encName] = $this->mapSmtpEncryption($account->smtp_encryption);
            $transport = new EsmtpTransport($account->smtp_host, (int)$account->smtp_port, $sslFlag);
            // STARTTLS if requested
            if ($encName === 'tls') {
                // On Symfony Mailer, enabling STARTTLS is done via setTls(true)
                if (method_exists($transport, 'setTls')) {
                    $transport->setTls(true);
                }
            }
            $transport->setUsername($account->smtp_username);
            $transport->setPassword(Crypt::decryptString($account->smtp_secret));
            $transport->start();
            $transport->stop();
            $res->smtp_ok = true;
        } catch (\Throwable $e) {
            [$code, $msg] = $this->smtpErrorClassify($e);
            $res->smtp_error_code = $code;
            $res->smtp_error_message = $msg;
            Log::warning('SMTP test failed', ['account_id' => $account->id, 'code' => $code, 'err' => $e->getMessage()]);
        } finally {
            $res->smtp_ms = (microtime(true) - $t1) * 1000.0;
        }

        // Persist health
        $account->last_test_at = Carbon::now();
        $account->last_test_result = $res->overall();
        $account->last_error_code = null;
        $account->last_error_message = null;
        if (!$res->imap_ok || !$res->smtp_ok) {
            // choose worst error
            if (!$res->imap_ok) {
                $account->last_error_code = $res->imap_error_code;
                $account->last_error_message = $res->imap_error_message;
            } else {
                $account->last_error_code = $res->smtp_error_code;
                $account->last_error_message = $res->smtp_error_message;
            }
        }
        if ($res->imap_ok) {
            $account->last_successful_fetch_at = Carbon::now();
        }
        if ($res->smtp_ok) {
            $account->last_successful_send_at = Carbon::now();
        }
        $account->save();

        return $res;
    }

    private function mapImapEncryption(?string $enc): ?string
    {
        $enc = strtolower((string)$enc);
        return match ($enc) {
            'ssl', 'tls' => $enc,
            'starttls' => 'tls',
            default => null,
        };
    }

    private function mapSmtpEncryption(?string $enc): array
    {
        $enc = strtolower((string)$enc);
        if ($enc === 'ssl') return [true, 'ssl'];
        if ($enc === 'tls' || $enc === 'starttls') return [false, 'tls'];
        return [false, null];
    }

    private function imapErrorClassify(\Throwable $e): array
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'auth')) return ['IMAP_AUTH', 'Authentication failed'];
        if (str_contains($m, 'connect') && str_contains($m, 'timeout')) return ['IMAP_CONNECT', 'Connection timed out'];
        if (str_contains($m, 'connect') && str_contains($m, 'refused')) return ['IMAP_CONNECT', 'Connection refused'];
        if (str_contains($m, 'tls') || str_contains($m, 'ssl')) return ['IMAP_TLS', 'TLS/SSL negotiation failed'];
        return ['IMAP_ERROR', $e->getMessage()];
    }

    private function smtpErrorClassify(\Throwable $e): array
    {
        $m = strtolower($e->getMessage());
        if (str_contains($m, 'auth')) return ['SMTP_AUTH', 'Authentication failed'];
        if (str_contains($m, 'connect') && str_contains($m, 'timeout')) return ['SMTP_CONNECT', 'Connection timed out'];
        if (str_contains($m, 'connect') && str_contains($m, 'refused')) return ['SMTP_CONNECT', 'Connection refused'];
        if (str_contains($m, 'tls') || str_contains($m, 'ssl') || str_contains($m, 'starttls')) return ['SMTP_TLS', 'TLS/SSL negotiation failed'];
        return ['SMTP_ERROR', $e->getMessage()];
    }
}
