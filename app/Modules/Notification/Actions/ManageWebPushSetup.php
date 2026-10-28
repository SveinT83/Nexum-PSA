<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Services\RunWebPushSetupCheck;
use App\Modules\Notification\Services\WebPushManagedRuntime;
use App\Modules\Notification\Services\WebPushPrivateStore;
use App\Modules\Notification\Services\WebPushRegistrationMutex;
use App\Modules\Notification\Services\WebPushSetupChecks;
use App\Modules\Notification\Support\LinuxWebPushInstallationProfile;
use App\Modules\Notification\Support\WebPushIdentity;
use App\Modules\Notification\Support\WebPushSetupAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Minishlink\WebPush\VAPID;

/** The browser and official API share one explicit installation lifecycle; keys are never input/output fields. */
final class ManageWebPushSetup
{
    public function __construct(private readonly WebPushPrivateStore $store, private readonly WebPushManagedRuntime $runtime,
        private readonly WebPushSetupChecks $checks, private readonly RunWebPushSetupCheck $check,
        private readonly WebPushRegistrationMutex $mutex) {}

    public function context(Request $request): array
    {
        WebPushSetupAccess::actor($request);

        return $this->checks->context() + ['database_supported' => $this->mutex->installed() && $this->mutex->sameDatabase()];
    }

    public function initialize(Request $request): array
    {
        [$actor, $data, $key, $hash] = $this->prepare($request, 'initialize', ['subject']);
        $this->requireDatabase();
        $result = $this->store->change($key, $hash, $data['revision'],
            function (#[\SensitiveParameter] ?array $current) use ($request, $data, $actor) {
                abort_if($current !== null, 409, 'A stable managed setup already exists; use its lifecycle controls.');
                WebPushSetupAccess::actor($request);
                $environment = ['public_key' => config('webpush.vapid.public_key'), 'private_key' => config('webpush.vapid.private_key'), 'subject' => $data['subject']];
                $hasPublic = filled($environment['public_key']);
                $hasPrivate = filled($environment['private_key']);
                abort_if(filled(config('webpush.vapid.pem_file')), 409, 'This installation uses a deployment-owned PEM identity; its operator must reconcile the supported identity source.');
                if ($hasPublic || $hasPrivate) {
                    abort_unless($hasPublic && $hasPrivate && WebPushIdentity::valid($environment), 409, 'The existing stable VAPID identity is incomplete or invalid; the installation operator must reconcile it.');
                    $next = ['source' => 'environment', 'public_fingerprint' => hash('sha256', $environment['public_key'])];
                } else {
                    abort_if(WebPushSubscription::exists(), 409, 'Existing device registrations need operator reconciliation before generating a replacement identity.');
                    $next = $this->generated();
                }
                $this->audit($actor, 'initialize', 'claimed');

                return $next + ['subject' => $data['subject'], 'enabled' => false, 'identity_version' => (string) Str::uuid(),
                    'previous_identity_revision' => null, 'has_been_enabled' => false, 'registration_reset_required' => false, 'pending_operation' => null];
            }, self::summary(...), fn () => WebPushSetupAccess::actor($request));

        return $this->response($request, $result, 'initialized');
    }

    public function check(Request $request): array
    {
        [$actor, $data] = $this->prepare($request, 'check');
        $this->requireDatabase();
        $this->audit($actor, 'check', 'claimed');
        $context = $this->check->run($request, $data['revision']);

        return ['context' => $context + ['database_supported' => true], 'operation' => [
            'state' => in_array($context['worker'], ['verified', 'unknown', 'blocked'], true) ? $context['worker'] : 'pending', 'replayed' => false,
            'revision' => $data['revision'], 'enabled' => $context['identity']['enabled']]];
    }

    public function activation(Request $request): array
    {
        [$actor, $data, $key, $hash] = $this->prepare($request, 'activation', ['enabled']);
        $enabled = $request->boolean('enabled');
        if ($enabled) {
            $this->requireDatabase();
        }
        $result = $this->store->change($key, $hash, $data['revision'],
            function (#[\SensitiveParameter] ?array $current) use ($request, $actor, $enabled) {
                abort_unless($current !== null, 409, 'Initialize a supported installation before changing activation.');
                WebPushSetupAccess::actor($request);
                if ($enabled) {
                    abort_unless($this->checks->context()['ready_to_enable'], 409, 'Activation requires the exact fresh trusted origin, service worker, identity and approved worker evidence.');
                    abort_unless(! WebPushSubscription::exists() || ($current['source'] ?? null) === 'environment' || ($current['has_been_enabled'] ?? false) === true, 409, 'Review existing registrations before enabling the replacement identity.');
                }
                $this->audit($actor, $enabled ? 'enable' : 'disable', 'claimed');
                $current['enabled'] = $enabled;
                if ($enabled) {
                    $current['has_been_enabled'] = true;
                }

                return $current;
            }, self::summary(...), fn () => WebPushSetupAccess::actor($request));

        return $this->response($request, $result, $enabled ? 'enabled' : 'disabled');
    }

    public function rotate(Request $request): array
    {
        return $this->replace($request, 'rotation');
    }

    public function rollback(Request $request): array
    {
        return $this->replace($request, 'rollback');
    }

    private function replace(Request $request, string $kind): array
    {
        [$actor, $data, $key, $hash] = $this->prepare($request, $kind, $kind === 'rollback' ? ['archive_revision', 'confirmation'] : ['confirmation']);
        $this->requireDatabase();
        $completed = $this->store->receipt(hash('sha256', $key.':commit'), $hash);
        if ($completed !== null) {
            return $this->response($request, $completed + ['replayed' => true], $kind === 'rotation' ? 'rotated_disabled' : 'restored_disabled');
        }
        $target = $kind === 'rollback' ? $data['archive_revision'] : null;
        if ($target !== null) {
            $historical = $this->store->historical($target);
            abort_unless(($historical['source'] ?? null) === 'managed' && WebPushIdentity::valid($historical), 409, 'The selected encrypted managed identity cannot be restored safely.');
        }
        $prepared = $this->store->change(hash('sha256', $key.':prepare'), $hash, $data['revision'],
            function (#[\SensitiveParameter] ?array $current) use ($request, $actor, $kind, $target) {
                WebPushSetupAccess::actor($request);
                abort_unless($current !== null && ($current['source'] ?? null) === 'managed', 409, 'Environment-owned identities require the installation operator; they are never silently replaced.');
                $pending = $current['pending_operation'] ?? null;
                if ($pending !== null) {
                    abort_unless(is_array($pending) && ($pending['kind'] ?? null) === $kind
                        && ($pending['target_revision'] ?? null) === $target, 409, 'Reconcile the recorded pending identity operation before starting a different one.');
                } else {
                    $pending = ['kind' => $kind, 'target_revision' => $target, 'base_revision' => $current['revision'],
                        'operation_id' => (string) Str::uuid()];
                }
                $this->audit($actor, $kind, 'disable_and_reconcile');
                $current['enabled'] = false;
                $current['registration_reset_required'] = true;
                $current['pending_operation'] = $pending;

                return $current;
            }, self::summary(...), fn () => WebPushSetupAccess::actor($request));
        $removed = $this->mutex->run(function () use ($request, $actor, $prepared, $kind, $target, $key, $hash) {
            WebPushSetupAccess::actor($request);
            if ($this->store->receipt(hash('sha256', $key.':commit'), $hash) !== null) {
                return 0;
            }
            $current = $this->store->read()['identity'];
            abort_unless($current && $current['revision'] === $prepared['revision']
                && ($current['registration_reset_required'] ?? false)
                && ($current['pending_operation']['kind'] ?? null) === $kind
                && ($current['pending_operation']['target_revision'] ?? null) === $target,
                412, 'The exact pending identity revision changed before registration cleanup.');
            $count = 0;
            WebPushSubscription::orderBy('id')->chunkById(200, function ($subscriptions) use ($request, $actor, &$count) {
                foreach ($subscriptions as $subscription) {
                    WebPushSetupAccess::actor($request);
                    $targetUser = $subscription->subscribable;
                    abort_unless($targetUser instanceof User, 409, 'A registration has an unresolved owner; reconcile it before completing identity replacement.');
                    app(RemoveWebPushSubscription::class)->handle($subscription, $targetUser, $actor, RemoveWebPushSubscription::ACTION_ADMINISTRATOR_REVOKED);
                    $count++;
                }
            });
            abort_if(WebPushSubscription::exists(), 409, 'Device registration cleanup is not complete.');

            return $count;
        });
        $result = $this->store->change(hash('sha256', $key.':commit'), $hash, $prepared['revision'],
            function (#[\SensitiveParameter] ?array $current) use ($request, $actor, $kind, $target, $removed) {
                WebPushSetupAccess::actor($request);
                abort_unless($current && ($current['registration_reset_required'] ?? false)
                    && ($current['pending_operation']['kind'] ?? null) === $kind
                    && ($current['pending_operation']['target_revision'] ?? null) === $target, 409, 'The recorded identity operation changed; reconcile it first.');
                $base = $current['pending_operation']['base_revision'];
                $next = $kind === 'rollback' ? $this->store->historical($target) : $this->generated();
                abort_unless($kind !== 'rollback' || ($next['source'] ?? null) === 'managed', 409);
                $this->audit($actor, $kind, 'publish_disabled', ['registrations_revoked' => $removed]);

                return ['source' => 'managed', 'public_key' => $next['public_key'], 'private_key' => $next['private_key'],
                    'subject' => $kind === 'rollback' ? $next['subject'] : $current['subject'],
                    'enabled' => false, 'identity_version' => (string) Str::uuid(), 'previous_identity_revision' => $base,
                    'has_been_enabled' => false, 'registration_reset_required' => false, 'pending_operation' => null];
            }, self::summary(...), fn () => WebPushSetupAccess::actor($request));

        return $this->response($request, $result + ['registrations_revoked' => $removed], $kind === 'rotation' ? 'rotated_disabled' : 'restored_disabled');
    }

    private function prepare(Request $request, string $kind, array $extra = []): array
    {
        $fields = array_merge(['revision', 'confirm', 'request_key'], $extra);
        $input = $request->all();
        $unknown = array_diff(array_keys($input), array_merge($fields, ['_token', '_method']));
        $safe = array_intersect_key($input, array_flip(array_merge($fields, ['_token', '_method'])));
        foreach (['revision', 'archive_revision', 'request_key'] as $name) {
            if (isset($safe[$name]) && (! is_string($safe[$name]) || ! ($name === 'revision' && $safe[$name] === 'uninitialized') && ! Str::isUuid($safe[$name]))) {
                $safe[$name] = null;
            }
        }
        if (isset($safe['subject']) && ! WebPushIdentity::validSubject($safe['subject'])) {
            $safe['subject'] = null;
        }
        if (isset($safe['enabled']) && ! in_array($safe['enabled'], [true, false, 0, 1, '0', '1'], true)) {
            $safe['enabled'] = null;
        }
        if (isset($safe['confirm']) && ! in_array($safe['confirm'], [true, 1, '1', 'yes', 'on'], true)) {
            $safe['confirm'] = null;
        }
        $phrase = $kind === 'rotation' ? 'ROTATE WEB PUSH' : 'ROLL BACK WEB PUSH';
        if (isset($safe['confirmation']) && $safe['confirmation'] !== $phrase) {
            $safe['confirmation'] = null;
        }
        $request->replace($safe); // Invalid private/unknown values must never become flashed old input.
        $request->query->replace([]);
        $actor = WebPushSetupAccess::actor($request);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['setup' => 'Only the documented safe setup fields are accepted. Private key material cannot be submitted.']);
        }
        $rules = ['revision' => ['required', 'string', 'max:80'], 'confirm' => ['required', 'accepted']];
        if (in_array('subject', $extra, true)) {
            $rules['subject'] = ['required', 'string', 'max:512'];
        }
        if (in_array('enabled', $extra, true)) {
            $rules['enabled'] = ['required', 'boolean'];
        }
        if (in_array('archive_revision', $extra, true)) {
            $rules['archive_revision'] = ['required', 'uuid'];
        }
        if (in_array('confirmation', $extra, true)) {
            $rules['confirmation'] = ['required', 'in:'.$phrase];
        }
        $data = $request->validate($rules);
        if ($request->is('api/*') && $kind !== 'check') {
            abort_unless(config('notification-api.managed_webpush_execution_enabled', false), 409, 'Managed Web Push API execution is disabled by the installation.');
        }
        if ($request->is('api/*')) {
            abort_unless($request->hasHeader('If-Match'), 428, 'Read the setup first; its exact If-Match is required.');
            abort_unless($request->header('If-Match') === '"'.$data['revision'].'"', 412, 'The exact setup If-Match is required.');
        }
        $identity = $request->header('Idempotency-Key') ?? $request->input('request_key');
        abort_unless(is_string($identity) && strlen($identity) >= 8 && strlen($identity) <= 200, 428, 'A fresh setup action identity is required.');
        $key = hash_hmac('sha256', LinuxWebPushInstallationProfile::installationId().':'.$actor->id.':'.$kind.':'.$identity, config('app.key'));
        $hash = hash_hmac('sha256', json_encode([$kind, $data], JSON_THROW_ON_ERROR), config('app.key'));

        return [$actor, $data, $key, $hash];
    }

    private function requireDatabase(): void
    {
        abort_unless($this->mutex->installed() && $this->mutex->sameDatabase(), 409, 'The supported managed installation requires its additive registration mutex and one subscription/audit database.');
    }

    private function generated(): array
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable) {
            throw new \RuntimeException('A protected VAPID identity could not be generated. No activation occurred.');
        }

        return ['source' => 'managed', 'public_key' => $keys['publicKey'], 'private_key' => $keys['privateKey']];
    }

    private function audit(User $actor, string $operation, string $phase, array $safe = []): void
    {
        abort_unless(config('activitylog.enabled', true), 503, 'Managed Web Push requires lifecycle auditing.');
        activity('notification')->causedBy($actor)->withProperties(['operation' => $operation, 'phase' => $phase] + $safe)
            ->log('Managed Web Push operation claimed');
    }

    private static function summary(#[\SensitiveParameter] array $identity): array
    {
        return ['revision' => $identity['revision'], 'enabled' => $identity['enabled'],
            'identity_version' => $identity['identity_version'], 'source' => $identity['source'],
            'registration_reset_required' => (bool) ($identity['registration_reset_required'] ?? false)];
    }

    private function response(Request $request, array $result, string $state): array
    {
        WebPushSetupAccess::actor($request);

        return ['context' => $this->context($request), 'operation' => $result + ['state' => $state]];
    }
}
