<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use Illuminate\Support\Facades\Crypt;

final class EmailProviderCredentialCipher
{
    /**
     * @param  array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}  $credentials
     * @return array<string, string>
     */
    public function encrypt(#[\SensitiveParameter] array $credentials): array
    {
        foreach ($credentials as $value) {
            if (blank($value)) {
                throw new EmailProviderSecurityException('credential_value_missing');
            }
        }

        return [
            'imap_username_encrypted' => Crypt::encryptString($credentials['imap_username']),
            'imap_secret_encrypted' => Crypt::encryptString($credentials['imap_secret']),
            'smtp_username_encrypted' => Crypt::encryptString($credentials['smtp_username']),
            'smtp_secret_encrypted' => Crypt::encryptString($credentials['smtp_secret']),
        ];
    }

    /**
     * @return array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}
     */
    public function decrypt(#[\SensitiveParameter] EmailProviderCredentialVersion $version): array
    {
        if (! $version->hasCiphertext()) {
            throw new EmailProviderSecurityException('credential_ciphertext_destroyed');
        }

        try {
            return [
                'imap_username' => Crypt::decryptString($version->imap_username_encrypted),
                'imap_secret' => Crypt::decryptString($version->imap_secret_encrypted),
                'smtp_username' => Crypt::decryptString($version->smtp_username_encrypted),
                'smtp_secret' => Crypt::decryptString($version->smtp_secret_encrypted),
            ];
        } catch (\Throwable) {
            // Crypt exceptions are deliberately severed so callers cannot
            // traverse a raw lower-level diagnostic from logs or reports.
            throw new EmailProviderSecurityException('credential_decryption_failed');
        }
    }

    /** @return array<string, null> */
    public function destroyedCiphertext(): array
    {
        return [
            'imap_username_encrypted' => null,
            'imap_secret_encrypted' => null,
            'smtp_username_encrypted' => null,
            'smtp_secret_encrypted' => null,
        ];
    }
}
