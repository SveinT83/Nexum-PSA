<?php

namespace App\Modules\Notification\Support;

use Illuminate\Support\Str;

class WebPushReadiness
{
    public function isReady(): bool
    {
        return (bool) config('webpush.enabled', false)
            && $this->configurationIssues() === [];
    }

    /**
     * Return a browser-safe readiness contract without private key material.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $enabled = (bool) config('webpush.enabled', false);
        $issues = $this->configurationIssues();
        $ready = $enabled && $issues === [];

        return [
            'enabled' => $enabled,
            'ready' => $ready,
            'state' => match (true) {
                ! $enabled => 'disabled',
                $issues !== [] => 'incomplete_configuration',
                default => 'ready',
            },
            'message' => match (true) {
                ! $enabled => 'Web Push is disabled for this Nexum environment.',
                $issues !== [] => 'Web Push configuration is incomplete. Contact an administrator.',
                default => 'Web Push is available on supported secure browsers.',
            },
            'public_key' => $ready ? (string) config('webpush.vapid.public_key') : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function configurationIssues(): array
    {
        $issues = [];

        if (blank(config('webpush.vapid.public_key'))) {
            $issues[] = 'public_key';
        }

        if (blank(config('webpush.vapid.private_key'))) {
            $issues[] = 'private_key';
        }

        if (! $this->validSubject(config('webpush.vapid.subject'))) {
            $issues[] = 'subject';
        }

        return $issues;
    }

    private function validSubject(mixed $subject): bool
    {
        if (! is_string($subject) || trim($subject) === '') {
            return false;
        }

        if (Str::startsWith($subject, 'mailto:')) {
            return filter_var(Str::after($subject, 'mailto:'), FILTER_VALIDATE_EMAIL) !== false;
        }

        if (! Str::startsWith($subject, 'https://')) {
            return false;
        }

        return filter_var($subject, FILTER_VALIDATE_URL) !== false;
    }
}
