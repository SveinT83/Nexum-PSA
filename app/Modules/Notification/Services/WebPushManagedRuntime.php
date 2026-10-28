<?php

namespace App\Modules\Notification\Services;

use App\Modules\Notification\Support\WebPushIdentity;

/** Resolve on every use; managed identity/state is never cached into an ordinary worker config snapshot. */
final class WebPushManagedRuntime
{
    public function __construct(private readonly WebPushPrivateStore $store) {}

    /** Internal secret-bearing transport contract. Do not return or serialize this array from an endpoint. */
    public function effective(): array
    {
        $state = $this->store->read();
        $environment = ['public_key' => config('webpush.vapid.public_key'),
            'private_key' => config('webpush.vapid.private_key'), 'subject' => config('webpush.vapid.subject')];
        foreach ($environment as $field => $value) {
            $environment[$field] = is_string($value) ? $value : null;
        }
        $identity = $state['identity'];
        $managed = $state['state'] === 'configured';
        $values = $environment;
        $issues = [];
        $enabled = (bool) config('webpush.enabled', false);
        if ($managed) {
            $enabled = ($identity['enabled'] ?? false) === true && ! ($identity['registration_reset_required'] ?? false);
            if (($identity['source'] ?? null) === 'managed') {
                $values = array_intersect_key($identity, array_flip(['public_key', 'private_key', 'subject']));
            } elseif (($identity['source'] ?? null) === 'environment') {
                $values['subject'] = $identity['subject'] ?? null;
                if (! hash_equals($identity['public_fingerprint'] ?? '', hash('sha256', (string) $values['public_key']))) {
                    $issues[] = 'environment_identity_changed';
                }
            } else {
                $issues[] = 'identity_source';
            }
            if (! WebPushIdentity::valid($values)) {
                $issues[] = 'identity';
            }
            if (! \Illuminate\Support\Str::isUuid($identity['identity_version'] ?? '')) {
                $issues[] = 'identity_version';
            }
        } elseif ($state['state'] === 'unavailable') {
            $enabled = false;
            $values = ['public_key' => null, 'private_key' => null, 'subject' => null];
            $issues[] = 'managed_identity_unavailable';
        }
        foreach (['public_key', 'private_key', 'subject'] as $field) {
            $values[$field] = is_string($values[$field] ?? null) ? $values[$field] : null;
        }
        if (blank($values['public_key'] ?? null)) {
            $issues[] = 'public_key';
        }
        if (blank($values['private_key'] ?? null)) {
            $issues[] = 'private_key';
        }
        // Retain the existing legacy runtime contract until an explicit managed initialization.
        if (! WebPushIdentity::validSubject($values['subject'] ?? null)) {
            $issues[] = 'subject';
        }
        if (config('webpush_setup.force_disabled', false)) {
            $enabled = false;
        }

        return $values + ['enabled' => $enabled, 'issues' => array_values(array_unique($issues)),
            'stored_enabled' => $managed ? (($identity['enabled'] ?? false) === true) : (bool) config('webpush.enabled', false),
            'managed' => $managed, 'state' => $state['state'], 'profile' => $state['profile'],
            'revision' => $identity['revision'] ?? 'uninitialized', 'source' => $identity['source'] ?? 'environment',
            'identity_version' => $identity['identity_version'] ?? null,
            'previous_identity_revision' => $identity['previous_identity_revision'] ?? null,
            'registration_reset_required' => (bool) ($identity['registration_reset_required'] ?? false),
            'pending_operation_kind' => in_array(data_get($identity, 'pending_operation.kind'), ['rotation', 'rollback'], true) ? data_get($identity, 'pending_operation.kind') : null,
            'pending_target_revision' => data_get($identity, 'pending_operation.target_revision')];
    }

    /** Safe metadata only; caller additionally enforces current Admin/native API authority. */
    public function summary(): array
    {
        $effective = $this->effective();

        return ['revision' => $effective['revision'], 'managed' => $effective['managed'],
            'enabled' => $effective['enabled'], 'stored_enabled' => $effective['stored_enabled'], 'state' => $effective['state'], 'source' => $effective['source'],
            'subject' => WebPushIdentity::validSubject($effective['subject'] ?? null) ? $effective['subject'] : null,
            'identity_valid' => WebPushIdentity::valid($effective),
            'public_fingerprint' => filled($effective['public_key'] ?? null) ? hash('sha256', $effective['public_key']) : null,
            'identity_version' => $effective['identity_version'], 'previous_identity_revision' => $effective['previous_identity_revision'],
            'registration_reset_required' => $effective['registration_reset_required'],
            'pending_operation_kind' => $effective['pending_operation_kind'],
            'pending_target_revision' => \Illuminate\Support\Str::isUuid($effective['pending_target_revision'] ?? '') ? $effective['pending_target_revision'] : null,
            'installation_supported' => $effective['profile']['supported'],
            'installation_reason' => $effective['profile']['reason_code'],
            'force_disabled' => (bool) config('webpush_setup.force_disabled', false), 'issues' => $effective['issues']];
    }
}
