<?php

namespace App\Modules\Documentation\Support;

use App\Modules\Documentation\Models\ShippingCarrier;

/**
 * Builds browser-only tracking links from allowlisted carrier configuration.
 *
 * These URLs are never fetched by Nexum. The raw configuration contract also
 * lets Storage resolve immutable carrier snapshots rather than mutable master
 * data when it renders historical shipments.
 */
class ShippingTrackingLinkResolver
{
    public const TRACKING_PLACEHOLDER = '{tracking_number}';

    public function forCarrier(
        ShippingCarrier $carrier,
        string $trackingNumber,
        ?string $directUrl = null,
    ): ?string {
        return $this->resolve(
            trackingNumber: $trackingNumber,
            directUrl: $directUrl,
            trackingUrlTemplate: $carrier->tracking_method === ShippingCarrier::TRACKING_TEMPLATE
                ? $carrier->tracking_url_template
                : null,
            trackingPageUrl: $carrier->tracking_page_url,
            allowedHosts: $carrier->allowed_tracking_hosts ?? [],
            configurationVerified: $carrier->verification_state === ShippingCarrier::VERIFICATION_VERIFIED,
        );
    }

    /**
     * Resolve a safe link using shipment-snapshot-compatible scalar values.
     *
     * @param  array<int, string>  $allowedHosts
     */
    public function resolve(
        string $trackingNumber,
        ?string $directUrl = null,
        ?string $trackingUrlTemplate = null,
        ?string $trackingPageUrl = null,
        array $allowedHosts = [],
        bool $configurationVerified = false,
    ): ?string {
        if ($directUrl && $this->isAllowedUrl($directUrl, $allowedHosts)) {
            return trim($directUrl);
        }

        $trackingNumber = trim($trackingNumber);

        if (
            $trackingNumber !== ''
            && $configurationVerified
            && $trackingUrlTemplate
            && $this->isValidTemplate($trackingUrlTemplate, $allowedHosts)
        ) {
            return str_replace(
                self::TRACKING_PLACEHOLDER,
                rawurlencode($trackingNumber),
                trim($trackingUrlTemplate),
            );
        }

        if ($trackingPageUrl && $this->isAllowedUrl($trackingPageUrl, $allowedHosts)) {
            return trim($trackingPageUrl);
        }

        return null;
    }

    /** @param array<int, string> $allowedHosts */
    public function isValidTemplate(string $template, array $allowedHosts): bool
    {
        $template = trim($template);

        if (substr_count($template, self::TRACKING_PLACEHOLDER) !== 1) {
            return false;
        }

        $withoutPlaceholder = str_replace(self::TRACKING_PLACEHOLDER, '', $template);
        if (str_contains($withoutPlaceholder, '{') || str_contains($withoutPlaceholder, '}')) {
            return false;
        }

        return $this->isAllowedUrl(
            str_replace(self::TRACKING_PLACEHOLDER, 'TRACKING123', $template),
            $allowedHosts,
        );
    }

    /** @param array<int, string> $allowedHosts */
    public function isAllowedUrl(string $url, array $allowedHosts): bool
    {
        if (! $this->isHttpsUrl($url)) {
            return false;
        }

        $host = strtolower(rtrim((string) parse_url(trim($url), PHP_URL_HOST), '.'));

        foreach ($this->normalizeAllowedHosts($allowedHosts) as $allowedHost) {
            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    public function isHttpsUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443);
    }

    /**
     * @param  array<int, string>  $allowedHosts
     * @return array<int, string>
     */
    public function normalizeAllowedHosts(array $allowedHosts): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(rtrim(trim((string) $host), '.')),
            $allowedHosts,
        ))));
    }
}
