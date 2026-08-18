<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;

class EmailProviderDnsResolver
{
    /** @return list<string> */
    public function resolve(#[\SensitiveParameter] string $host): array
    {
        if (@inet_pton($host) !== false) {
            return [strtolower((string) inet_ntop((string) inet_pton($host)))];
        }

        $maxAnswers = max(1, min(64, (int) config('email_provider_security.dns.max_answers', 16)));
        $maxDepth = max(0, min(16, (int) config('email_provider_security.dns.max_cname_depth', 8)));
        $addresses = [];
        $pending = [strtolower($host)];
        $queued = [strtolower($host) => true];
        $visited = [];
        $depth = 0;

        while ($pending !== []) {
            if ($depth++ > $maxDepth) {
                throw new EmailProviderSecurityException('dns_cname_limit');
            }

            $name = array_shift($pending);
            unset($queued[$name]);

            if (isset($visited[$name])) {
                throw new EmailProviderSecurityException('dns_cname_loop');
            }

            $visited[$name] = true;
            $records = $this->lookup($name);

            foreach ($records as $record) {
                $type = strtoupper((string) ($record['type'] ?? ''));

                if ($type === 'A' && filled($record['ip'] ?? null)) {
                    $addresses[] = (string) $record['ip'];
                } elseif ($type === 'AAAA' && filled($record['ipv6'] ?? null)) {
                    $addresses[] = (string) $record['ipv6'];
                } elseif ($type === 'CNAME' && filled($record['target'] ?? null)) {
                    $target = strtolower(rtrim((string) $record['target'], '.'));

                    if (isset($visited[$target])) {
                        throw new EmailProviderSecurityException('dns_cname_loop');
                    }

                    if (! isset($queued[$target])) {
                        $pending[] = $target;
                        $queued[$target] = true;
                    }
                }

                if (count($addresses) + count($pending) > $maxAnswers) {
                    throw new EmailProviderSecurityException('dns_answer_limit');
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses, SORT_STRING);

        if ($addresses === []) {
            throw new EmailProviderSecurityException('dns_no_address');
        }

        return $addresses;
    }

    /** @return array<int, array<string, mixed>> */
    protected function lookup(#[\SensitiveParameter] string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);

        if (! is_array($records)) {
            throw new EmailProviderSecurityException('dns_lookup_failed');
        }

        return $records;
    }
}
