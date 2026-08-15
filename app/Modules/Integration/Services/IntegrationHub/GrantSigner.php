<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;

class GrantSigner
{
    /** @param array<string, mixed> $claims @return array{token:string,key_id:string,claims_digest:string} */
    public function sign(array $claims): array
    {
        $keyId = (string) config('integration-hub.active_grant_key_id');
        $key = (string) config('integration-hub.active_grant_key');
        $this->assertKey($keyId, $key);

        $header = ['alg' => 'HS256', 'typ' => 'NEXUM-EG+JWT', 'kid' => $keyId];
        $encodedHeader = $this->encodeJson($header);
        $encodedClaims = $this->encodeJson($claims);
        $signature = $this->base64UrlEncode(hash_hmac('sha256', $encodedHeader.'.'.$encodedClaims, $key, true));

        return [
            'token' => $encodedHeader.'.'.$encodedClaims.'.'.$signature,
            'key_id' => $keyId,
            'claims_digest' => hash('sha256', $encodedClaims),
        ];
    }

    /** @return array{claims:array<string,mixed>,claims_digest:string,key_id:string} */
    public function verify(string $token): array
    {
        if ($token === '' || strlen($token) > 8192) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }

        [$encodedHeader, $encodedClaims, $providedSignature] = $parts;
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $encodedHeader)
            || ! preg_match('/^[A-Za-z0-9_-]+$/', $encodedClaims)
            || ! preg_match('/^[A-Za-z0-9_-]{43}$/', $providedSignature)) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }
        $header = $this->decodeJson($encodedHeader);
        $claims = $this->decodeJson($encodedClaims);
        if (($header['alg'] ?? null) !== 'HS256' || ($header['typ'] ?? null) !== 'NEXUM-EG+JWT' || ! is_string($header['kid'] ?? null)) {
            throw new IntegrationHubDeniedException('grant_header_invalid');
        }

        $keyId = $header['kid'];
        $key = $this->verificationKey($keyId);
        $expected = $this->base64UrlEncode(hash_hmac('sha256', $encodedHeader.'.'.$encodedClaims, $key, true));
        if (! hash_equals($expected, $providedSignature)) {
            throw new IntegrationHubDeniedException('grant_signature_invalid');
        }

        return ['claims' => $claims, 'claims_digest' => hash('sha256', $encodedClaims), 'key_id' => $keyId];
    }

    private function verificationKey(string $keyId): string
    {
        $activeId = (string) config('integration-hub.active_grant_key_id');
        if (hash_equals($activeId, $keyId)) {
            $key = (string) config('integration-hub.active_grant_key');
            $this->assertKey($activeId, $key);

            return $key;
        }

        $previousId = (string) config('integration-hub.previous_grant_key_id');
        if ($previousId !== '' && hash_equals($previousId, $keyId)) {
            $key = (string) config('integration-hub.previous_grant_key');
            $this->assertKey($previousId, $key);

            return $key;
        }

        throw new IntegrationHubDeniedException('grant_key_unknown');
    }

    private function assertKey(string $keyId, string $key): void
    {
        if ($keyId === '' || strlen($key) < 32) {
            throw new IntegrationHubDeniedException('grant_signing_unavailable', 'Grant signing is unavailable.', 503, 'unavailable');
        }
    }

    /** @param array<string, mixed> $value */
    private function encodeJson(array $value): string
    {
        return $this->base64UrlEncode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $value): array
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }
        try {
            $result = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }

        if (! is_array($result)) {
            throw new IntegrationHubDeniedException('grant_malformed');
        }

        return $result;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
