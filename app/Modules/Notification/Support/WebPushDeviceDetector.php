<?php

namespace App\Modules\Notification\Support;

class WebPushDeviceDetector
{
    /**
     * Convert an untrusted user-agent string into coarse, non-authoritative
     * display metadata. The raw string is deliberately not persisted.
     *
     * @return array{label: string, browser: string, platform: string}
     */
    public function detect(?string $userAgent): array
    {
        $userAgent ??= '';

        $browser = match (true) {
            preg_match('/Edg(?:A|iOS)?\//i', $userAgent) === 1 => 'Microsoft Edge',
            preg_match('/OPR\//i', $userAgent) === 1 => 'Opera',
            preg_match('/CriOS\//i', $userAgent) === 1 => 'Google Chrome',
            preg_match('/Chrome\//i', $userAgent) === 1 => 'Google Chrome',
            preg_match('/FxiOS\//i', $userAgent) === 1 => 'Mozilla Firefox',
            preg_match('/Firefox\//i', $userAgent) === 1 => 'Mozilla Firefox',
            preg_match('/Safari\//i', $userAgent) === 1 => 'Safari',
            default => 'Other browser',
        };

        $platform = match (true) {
            preg_match('/iPad|iPhone|iPod/i', $userAgent) === 1 => 'iOS/iPadOS',
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/Windows/i', $userAgent) === 1 => 'Windows',
            preg_match('/Macintosh|Mac OS X/i', $userAgent) === 1 => 'macOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'Other platform',
        };

        return [
            'label' => "{$browser} on {$platform}",
            'browser' => $browser,
            'platform' => $platform,
        ];
    }
}
