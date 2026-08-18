<?php

namespace App\Modules\Integration\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use Illuminate\Support\Facades\Crypt;

final class EmailProviderLegacyAccountMaterial
{
    /** @var list<string> */
    private const FIELDS = [
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_secret',
        'imap_auth_type',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_secret',
        'smtp_auth_type',
    ];

    public function isComplete(#[\SensitiveParameter] EmailAccount $account): bool
    {
        foreach (self::FIELDS as $field) {
            if (blank($account->getAttribute($field))) {
                return false;
            }
        }

        return true;
    }

    public function legacyFingerprint(#[\SensitiveParameter] EmailAccount $account): string
    {
        $values = ['email_account_id' => (int) $account->id];

        foreach (self::FIELDS as $field) {
            $values[$field] = $account->getRawOriginal($field);
        }

        return $this->fingerprint($values);
    }

    public function bindingFingerprint(#[\SensitiveParameter] EmailAccount $account): string
    {
        return $this->fingerprint([
            'email_account_id' => (int) $account->id,
            'source' => (string) $account->getAttribute('provider_credential_source'),
            'provider_integration_id' => $account->getAttribute('provider_integration_id'),
            'binding_version' => (int) $account->getAttribute('provider_binding_version'),
        ]);
    }

    /**
     * @return array{imap_username:string,imap_secret:string,smtp_username:string,smtp_secret:string}
     */
    public function decrypt(#[\SensitiveParameter] EmailAccount $account): array
    {
        if (! $this->isComplete($account)) {
            throw new EmailProviderSecurityException('legacy_material_incomplete');
        }

        try {
            return [
                'imap_username' => (string) $account->imap_username,
                'imap_secret' => Crypt::decryptString((string) $account->imap_secret),
                'smtp_username' => (string) $account->smtp_username,
                'smtp_secret' => Crypt::decryptString((string) $account->smtp_secret),
            ];
        } catch (\Throwable) {
            throw new EmailProviderSecurityException('legacy_material_corrupt');
        }
    }

    /** @param array<string, mixed> $values */
    private function fingerprint(#[\SensitiveParameter] array $values): string
    {
        $encoded = json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $encoded, (string) config('app.key'));
    }
}
