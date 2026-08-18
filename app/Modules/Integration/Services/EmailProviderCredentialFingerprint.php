<?php

namespace App\Modules\Integration\Services;

final class EmailProviderCredentialFingerprint
{
    /**
     * @param  array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}  $credentials
     */
    public function make(#[\SensitiveParameter] array $credentials): string
    {
        $payload = json_encode([
            'imap_username' => $credentials['imap_username'],
            'imap_secret' => $credentials['imap_secret'],
            'smtp_username' => $credentials['smtp_username'],
            'smtp_secret' => $credentials['smtp_secret'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }
}
