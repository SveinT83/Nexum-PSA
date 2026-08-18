<?php

namespace App\Providers;

use App\Models\Core\User;
use App\Modules\Integration\Support\EmailProviderTelemetryRedactor;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if (! EmailProviderTelemetryRedactor::sanitize($entry)) {
                return false;
            }

            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        // Development Telescope is network-accessible in some installations.
        // Provider material is therefore redacted in every environment, not
        // only production.
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'imap_secret',
            'imap_password',
            'imap_host',
            'imap_port',
            'imap_transport',
            'imap_auth_type',
            'smtp_secret',
            'smtp_password',
            'smtp_host',
            'smtp_port',
            'smtp_transport',
            'smtp_auth_type',
            'credential',
            'credentials',
            'client_secret',
            'access_token',
            'refresh_token',
            'api_key',
            'trust_mode',
            'trusted_cidr_name',
            'private_endpoint_reason',
            '_old_input.imap_secret',
            '_old_input.imap_password',
            '_old_input.smtp_secret',
            '_old_input.smtp_password',
            '_old_input.credentials',
            '_old_input.client_secret',
            '_old_input.access_token',
            '_old_input.refresh_token',
            '_old_input.api_key',
        ]);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (?User $user): bool {
            return $user instanceof User
                && $user->status === User::STATUS_ACTIVE
                && $user->can('system.telescope_view');
        });
    }

    /**
     * Telescope's package default bypasses its gate in local environments.
     * This Dev installation may be network reachable, so require the same
     * explicit active-user authorization in every environment.
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function ($request): bool {
            $user = $request->user();

            return $user instanceof User
                && $user->status === User::STATUS_ACTIVE
                && $user->can('system.telescope_view');
        });
    }
}
