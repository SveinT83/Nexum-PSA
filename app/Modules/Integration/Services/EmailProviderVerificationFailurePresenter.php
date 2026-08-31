<?php

namespace App\Modules\Integration\Services;

final class EmailProviderVerificationFailurePresenter
{
    public function message(string $reasonCode): string
    {
        return match ($reasonCode) {
            'protocol_not_supported',
            'port_not_allowed',
            'transport_mismatch',
            'host_syntax_invalid',
            'host_idna_invalid',
            'host_length_invalid',
            'host_label_invalid',
            'imap_transport_invalid',
            'smtp_transport_invalid',
            'endpoint_allowlist_invalid',
            'endpoint_allowlist_duplicate',
            'endpoint_policy_snapshot_stale',
            'trust_mode_invalid',
            'trusted_cidr_mismatch' => 'The provider endpoint, port, transport, or approved trust policy does not match the allowed configuration. Review the staged connection settings before retrying Verify.',

            'dns_answer_set_denied',
            'dns_cname_limit',
            'dns_cname_loop',
            'dns_answer_limit',
            'dns_no_address',
            'dns_lookup_failed',
            'address_always_denied',
            'address_not_public',
            'trusted_address_not_private',
            'address_invalid' => 'The provider address was rejected by DNS or address policy. Review the configured hostname and approved network scope before retrying Verify.',

            'provider_connection_failed' => 'Nexum could not establish a trusted TLS connection to the provider. Check certificate trust, transport, and provider reachability before retrying Verify.',

            'provider_authentication_rejected' => 'The provider rejected the staged credentials. Check the IMAP and SMTP credentials before retrying Verify.',

            'provider_verification_deadline_unavailable',
            'provider_verification_cleanup_deadline_exceeded',
            'provider_verification_deadline_exceeded' => 'Provider verification timed out. Check provider reachability, wait for any active attempt to finish, and retry Verify.',

            'verification_in_progress',
            'provider_lifecycle_locked',
            'provider_work_not_drained',
            'provider_reconciliation_active',
            'provider_verification_deadline_conflict' => 'Another provider verification or lifecycle operation is in progress. Wait for it to finish before retrying Verify.',

            'credential_not_verifiable',
            'verification_snapshot_stale',
            'provider_verification_stale',
            'credential_version_invalid' => 'The staged credential or provider configuration changed before verification completed. Reload the provider page and verify the current staged version.',

            default => 'Nexum could not verify the provider safely. Check the staged credentials, TLS settings, and reachability before retrying Verify.',
        };
    }
}
