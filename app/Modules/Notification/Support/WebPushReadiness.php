<?php

namespace App\Modules\Notification\Support;

class WebPushReadiness
{
    public function isReady(): bool
    {
        $effective = app(\App\Modules\Notification\Services\WebPushManagedRuntime::class)->effective();

        return $effective['enabled'] && $effective['issues'] === [];
    }

    /**
     * Return a browser-safe readiness contract without private key material.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $effective = app(\App\Modules\Notification\Services\WebPushManagedRuntime::class)->effective();
        $enabled = $effective['enabled'];
        $issues = $effective['issues'];
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
            'public_key' => $ready ? (string) $effective['public_key'] : null,
        ];
    }

    /**
     * @return list<string>
     */
    public function configurationIssues(): array
    {
        return app(\App\Modules\Notification\Services\WebPushManagedRuntime::class)->effective()['issues'];
    }
}
