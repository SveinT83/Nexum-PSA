<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\IntegrationHubDomain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PleskXmlClient
{
    /** @return array<string, mixed> */
    public function inspect(Integration $integration, IntegrationHubDomain $domain): array
    {
        $endpoint = $this->endpoint((string) $integration->server);
        $key = (string) ($integration->getSecret('api_key') ?: $integration->getSecret('secret_key'));
        if (! $endpoint || $key === '') {
            return $this->failure('unavailable', 'provider_misconfigured');
        }

        $packet = $this->packet($domain);
        $response = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'KEY' => $key,
                    'Accept' => 'text/xml',
                ])->withoutRedirecting()
                    ->withBody($packet, 'text/xml')
                    ->connectTimeout(min(10, max(1, (int) config('integration-hub.plesk.connect_timeout_seconds', 3))))
                    ->timeout(min(30, max(2, (int) config('integration-hub.plesk.timeout_seconds', 10))))
                    ->post($endpoint);
            } catch (ConnectionException) {
                if ($attempt === 2) {
                    return $this->failure('unavailable', 'provider_timeout_or_connection_failed', true);
                }
                $this->retryDelay();

                continue;
            }

            if (! in_array($response->status(), [429, 502, 503, 504], true) || $attempt === 2) {
                break;
            }
            $this->retryDelay();
        }

        if (! $response instanceof Response) {
            return $this->failure('unavailable', 'provider_unavailable', true);
        }
        if (in_array($response->status(), [401, 403], true)) {
            return $this->failure('unavailable', 'provider_authentication_failed');
        }
        if ($response->status() === 429) {
            return $this->failure('unavailable', 'provider_rate_limited', true);
        }
        if ($response->serverError()) {
            return $this->failure('unavailable', 'provider_unavailable', true);
        }
        if (! $response->successful()) {
            return $this->failure('failed', 'provider_request_rejected');
        }

        $body = $response->body();
        if (strlen($body) > max(1024, (int) config('integration-hub.plesk.max_response_bytes', 1048576))) {
            return $this->failure('failed', 'provider_response_too_large');
        }

        return $this->parse($body, $domain);
    }

    private function endpoint(string $server): ?string
    {
        $parts = parse_url(trim($server));
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || ! isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';

        return 'https://'.$parts['host'].$port.'/enterprise/control/agent.php';
    }

    private function packet(IntegrationHubDomain $domain): string
    {
        $hostname = htmlspecialchars($domain->hostname_ascii, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $reference = trim((string) $domain->provider_reference);
        $subscriptionFilter = ctype_digit($reference)
            ? '<id>'.(int) $reference.'</id>'
            : '<name>'.htmlspecialchars($reference !== '' ? $reference : $domain->hostname_ascii, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</name>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<packet>'
            .'<webspace><get><filter>'.$subscriptionFilter.'</filter><dataset><gen_info/><hosting/></dataset></get></webspace>'
            .'<site><get><filter><name>'.$hostname.'</name></filter><dataset><gen_info/><hosting/><prefs/></dataset></get></site>'
            .'<site-alias><get><filter><site-name>'.$hostname.'</site-name></filter></get></site-alias>'
            .'</packet>';
    }

    /** @return array<string, mixed> */
    private function parse(string $body, IntegrationHubDomain $domain): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (! $xml) {
            return $this->failure('failed', 'provider_schema_invalid');
        }

        $webspace = $xml->webspace->get->result[0] ?? null;
        $site = $xml->site->get->result[0] ?? null;
        if (! $webspace || ! $site) {
            return $this->failure('failed', 'provider_schema_incomplete');
        }
        foreach ([$webspace, $site] as $result) {
            if ((string) $result->status !== 'ok') {
                $code = (string) ($result->errcode ?? '');

                return $this->failure($code === '1013' ? 'unknown' : 'failed', $code === '1013' ? 'provider_mapping_not_found' : 'provider_operation_failed');
            }
        }

        $siteInfo = $site->data->gen_info ?? null;
        $subscriptionInfo = $webspace->data->gen_info ?? null;
        $subscriptionId = (string) ($webspace->id ?? '');
        $siteId = (string) ($site->id ?? '');
        $siteWebspaceId = (string) ($siteInfo->{'webspace-id'} ?? '');
        if ($subscriptionId === '' || $siteId === '' || $siteWebspaceId === '' || $siteWebspaceId !== $subscriptionId) {
            return $this->failure('unknown', 'provider_site_subscription_mismatch');
        }
        $reference = trim((string) $domain->provider_reference);
        $subscriptionName = strtolower((string) ($subscriptionInfo->{'ascii-name'} ?? $subscriptionInfo->name ?? ''));
        if (($reference !== '' && ctype_digit($reference) && $subscriptionId !== $reference)
            || ($reference !== '' && ! ctype_digit($reference) && $subscriptionName !== strtolower($reference))) {
            return $this->failure('unknown', 'provider_subscription_mismatch');
        }
        $providerHostname = strtolower((string) ($siteInfo->{'ascii-name'} ?? $siteInfo->name ?? ''));
        if ($providerHostname === '' || $providerHostname !== strtolower($domain->hostname_ascii)) {
            return $this->failure('unknown', 'provider_hostname_mismatch');
        }

        $aliases = [];
        foreach ($xml->xpath('/packet/site-alias/get/result') ?: [] as $aliasResult) {
            if ((string) $aliasResult->status === 'ok' && isset($aliasResult->info)) {
                if ((string) ($aliasResult->info->{'site-id'} ?? '') !== $siteId) {
                    return $this->failure('unknown', 'provider_alias_site_mismatch');
                }
                $aliases[] = [
                    'name' => strtolower((string) ($aliasResult->info->{'ascii-name'} ?? $aliasResult->info->name)),
                    'enabled' => (string) ($aliasResult->info->status ?? '0') === '0',
                ];
            }
        }

        $runtime = [];
        $allowedProperties = ['php', 'php_handler_type', 'ssl', 'webstat', 'fastcgi'];
        foreach ($site->data->hosting->vrt_hst->property ?? [] as $property) {
            $name = (string) ($property->name ?? '');
            if (in_array($name, $allowedProperties, true)) {
                $runtime[$name] = mb_substr((string) ($property->value ?? ''), 0, 120);
            }
        }

        return [
            'status' => 'ok',
            'reason_code' => null,
            'retryable' => false,
            'observed_at' => now(),
            'data' => [
                'account' => ['owner_reference' => (string) ($subscriptionInfo->{'owner-id'} ?? '') ?: null],
                'subscription' => [
                    'provider_id' => $subscriptionId,
                    'name' => $subscriptionName,
                    'status' => $this->status((string) ($subscriptionInfo->status ?? '')),
                    'hosting_type' => (string) ($subscriptionInfo->htype ?? '') ?: null,
                    'created_at' => (string) ($subscriptionInfo->cr_date ?? '') ?: null,
                ],
                'site' => [
                    'provider_id' => $siteId,
                    'hostname' => $providerHostname,
                    'status' => $this->status((string) ($siteInfo->status ?? '')),
                    'hosting_type' => (string) ($siteInfo->htype ?? '') ?: null,
                    'runtime' => $runtime,
                ],
                'aliases' => $aliases,
            ],
        ];
    }

    private function status(string $value): string
    {
        if (! ctype_digit($value)) {
            return 'unknown';
        }
        $flags = (int) $value;

        return match (true) {
            $flags === 0 => 'active',
            ($flags & 256) === 256 => 'expired',
            ($flags & 16) === 16 => 'disabled_by_admin',
            ($flags & 32) === 32 => 'disabled_by_reseller',
            ($flags & 64) === 64 => 'disabled_by_customer',
            ($flags & 4) === 4 => 'backup_or_restore',
            default => 'unknown',
        };
    }

    /** @return array<string, mixed> */
    private function failure(string $status, string $reason, bool $retryable = false): array
    {
        return ['status' => $status, 'reason_code' => $reason, 'retryable' => $retryable, 'observed_at' => now(), 'data' => null];
    }

    private function retryDelay(): void
    {
        $minimum = max(0, (int) config('integration-hub.plesk.retry_delay_min_ms', 50));
        $maximum = max($minimum, (int) config('integration-hub.plesk.retry_delay_max_ms', 150));
        if ($maximum > 0) {
            usleep(random_int($minimum, $maximum) * 1000);
        }
    }
}
