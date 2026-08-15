<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;

class DomainNormalizer
{
    /** @return array{ascii:string,unicode:string} */
    public function normalize(string $hostname): array
    {
        $unicode = mb_strtolower(rtrim(trim($hostname), '.'));
        if ($unicode === '' || strlen($unicode) > 1024 || str_contains($unicode, '://') || str_contains($unicode, '/') || str_contains($unicode, '@')) {
            throw new IntegrationHubDeniedException('domain_invalid', 'Domain name is invalid.', 422, 'failed');
        }

        $ascii = $unicode;
        if (preg_match('/[^\x20-\x7E]/', $unicode)) {
            if (! function_exists('idn_to_ascii')) {
                throw new IntegrationHubDeniedException('idn_normalization_unavailable', 'IDN normalization is unavailable.', 503, 'unavailable');
            }
            $converted = idn_to_ascii($unicode, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($converted === false) {
                throw new IntegrationHubDeniedException('domain_invalid', 'Domain name is invalid.', 422, 'failed');
            }
            $ascii = strtolower($converted);
        }

        if (strlen($ascii) > 253 || ! preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii)) {
            throw new IntegrationHubDeniedException('domain_invalid', 'Domain name is invalid.', 422, 'failed');
        }

        return ['ascii' => $ascii, 'unicode' => $unicode];
    }
}
