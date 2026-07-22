<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Exceptions\CloudFactoryApiException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CloudFactoryApiClient
{
    public function __construct(
        private readonly Integration $integration,
        private readonly CloudFactoryAudit $audit,
    ) {}

    public function connect(string $refreshToken): array
    {
        $this->integration->setSecret('refresh_token', trim($refreshToken));
        $this->integration->secrets = collect($this->integration->secrets ?? [])
            ->except(['access_token', 'id_token'])
            ->all();
        $this->integration->save();

        $token = $this->exchangeRefreshToken(true);
        $partnerResponse = $this->send(
            'GET',
            '/v2/partners/partners/Self',
            [],
            (string) $token['accessToken']
        );
        if (! $partnerResponse->successful()) {
            throw $this->failure($partnerResponse, 'Cloud Factory partner identity verification failed.');
        }
        $partner = $partnerResponse->json() ?? [];
        $roles = $this->fetchRoles((string) ($token['accessToken'] ?? ''));

        $config = $this->integration->config ?? [];
        $config['partner'] = $partner;
        $config['roles'] = $roles;
        $config['connected_at'] = now()->toIso8601String();
        $config['roles_checked_at'] = now()->toIso8601String();
        $config['capabilities'] = $this->capabilities($roles);
        unset($config['roles_last_error'], $config['roles_last_error_at']);
        $this->integration->forceFill([
            'status' => 'active',
            'server' => CloudFactoryIntegration::DEFAULT_SERVER,
            'config' => $config,
            'is_healthy' => true,
            'last_error' => null,
        ])->save();

        $this->audit->record('connection.connected', $this->integration, metadata: [
            'roles' => $roles,
            'partner_id' => data_get($partner, 'id'),
        ]);

        return ['partner' => $partner, 'roles' => $roles];
    }

    /**
     * Refresh the provider role snapshot without asking for a new refresh token.
     */
    public function refreshCapabilities(): array
    {
        if ($this->integration->status !== 'active') {
            throw new CloudFactoryApiException('Cloud Factory integration is not active.');
        }

        try {
            $roles = $this->fetchRoles($this->accessToken(false));
            $capabilities = $this->storeRoleSnapshot($roles);
        } catch (Throwable $exception) {
            $this->rememberRoleRefreshFailure($exception);
            $this->audit->record('connection.capabilities_refresh_failed', $this->integration, metadata: [
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $this->audit->record('connection.capabilities_refreshed', $this->integration, metadata: [
            'roles' => $roles,
            'source' => 'manual',
        ]);

        return ['roles' => $roles, 'capabilities' => $capabilities];
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    public function post(string $path, array $payload = []): array
    {
        return $this->request('POST', $path, ['json' => $payload]);
    }

    public function put(string $path, array $payload = []): array
    {
        return $this->request('PUT', $path, ['json' => $payload]);
    }

    public function patch(string $path, array $payload = []): array
    {
        return $this->request('PATCH', $path, ['json' => $payload]);
    }

    public function delete(string $path, array $payload = []): array
    {
        return $this->request('DELETE', $path, ['json' => $payload]);
    }

    public function revokeAllTokens(): void
    {
        $response = $this->send(
            'GET',
            '/Authenticate/RevokeAllTokens',
            ['query' => ['isCustomer' => 'false']],
            $this->accessToken()
        );

        if (! in_array($response->status(), [202, 204], true)) {
            throw $this->failure($response, 'Cloud Factory token revocation failed.');
        }

        $this->audit->record('connection.revoked', $this->integration, metadata: [
            'provider_status' => $response->status(),
        ]);

        $this->integration->forceFill([
            'status' => 'disabled',
            'secrets' => [],
            'is_healthy' => false,
            'last_error' => null,
            'config' => collect($this->integration->config ?? [])
                ->except(['access_token_expires_at', 'connected_at'])
                ->all(),
        ])->save();
    }

    public function roles(): array
    {
        return array_values(array_unique(array_map(
            'strval',
            $this->integration->config['roles'] ?? []
        )));
    }

    public function hasRole(string $role): bool
    {
        return collect($this->roles())->contains(
            fn (string $current): bool => strcasecmp($current, $role) === 0
        );
    }

    private function request(string $method, string $path, array $options): array
    {
        if ($this->integration->status !== 'active') {
            throw new CloudFactoryApiException('Cloud Factory integration is not active.');
        }

        $response = $this->send($method, $path, $options, $this->accessToken());

        if ($response->status() === 401) {
            $this->forgetAccessToken();
            $response = $this->send($method, $path, $options, $this->accessToken());
        }

        if (! $response->successful()) {
            throw $this->failure($response, 'Cloud Factory request failed.');
        }

        if ($response->status() === 204 || blank($response->body())) {
            return [];
        }

        return $response->json() ?? [];
    }

    private function send(string $method, string $path, array $options, ?string $accessToken): Response
    {
        try {
            $request = $this->pendingRequest();

            if ($accessToken) {
                $request = $request->withToken($accessToken);
            }

            return $request->send($method, ltrim($path, '/'), $options);
        } catch (Throwable $exception) {
            throw new CloudFactoryApiException(
                'Cloud Factory could not be reached.',
                context: ['exception' => $exception::class]
            );
        }
    }

    private function pendingRequest(): PendingRequest
    {
        return Http::baseUrl(rtrim(
            $this->integration->server ?: CloudFactoryIntegration::DEFAULT_SERVER,
            '/'
        ))
            ->acceptJson()
            ->asJson()
            ->timeout(40)
            ->connectTimeout(10)
            ->retry(
                [250, 1000, 2500],
                fn (Throwable $exception, PendingRequest $request): bool => true,
                throw: false
            );
    }

    private function accessToken(bool $refreshRoles = true): string
    {
        $expiresAt = data_get($this->integration->config, 'access_token_expires_at');
        $stored = $this->integration->getSecret('access_token');

        if ($stored && $expiresAt && now()->addMinutes(5)->lt(Carbon::parse($expiresAt))) {
            return $stored;
        }

        return Cache::lock('cloudfactory-token-'.$this->integration->id, 20)
            ->block(10, function () use ($refreshRoles): string {
                $this->integration->refresh();
                $expiresAt = data_get($this->integration->config, 'access_token_expires_at');
                $stored = $this->integration->getSecret('access_token');

                if ($stored && $expiresAt && now()->addMinutes(5)->lt(Carbon::parse($expiresAt))) {
                    return $stored;
                }

                $token = $this->exchangeRefreshToken();
                $accessToken = (string) $token['accessToken'];

                if ($refreshRoles) {
                    $this->refreshRoleSnapshotAfterTokenExchange($accessToken);
                }

                return $accessToken;
            });
    }

    private function exchangeRefreshToken(bool $connecting = false): array
    {
        $refreshToken = $this->integration->getSecret('refresh_token');

        if (! $refreshToken) {
            throw new CloudFactoryApiException('Cloud Factory refresh token is missing.');
        }

        $response = $this->send(
            'POST',
            '/v1/users/authentication/exchange-refresh-token',
            ['json' => ['refreshToken' => $refreshToken, 'isCustomer' => false]],
            null
        );

        if (! $response->successful() || blank($response->json('accessToken'))) {
            if (! $connecting) {
                $this->integration->forceFill([
                    'is_healthy' => false,
                    'last_error' => 'Token exchange failed with provider status '.$response->status().'.',
                ])->save();
            }

            throw $this->failure($response, 'Cloud Factory token exchange failed.');
        }

        $payload = $response->json();
        $this->integration->setSecret('access_token', (string) $payload['accessToken']);

        if (filled($payload['idToken'] ?? null)) {
            $this->integration->setSecret('id_token', (string) $payload['idToken']);
        }

        $config = $this->integration->config ?? [];
        $config['access_token_expires_at'] = now()
            ->addSeconds(max(60, (int) ($payload['expiresIn'] ?? 86400)))
            ->toIso8601String();
        $this->integration->config = $config;
        $this->integration->save();

        return $payload;
    }

    private function forgetAccessToken(): void
    {
        $this->integration->secrets = collect($this->integration->secrets ?? [])
            ->except(['access_token', 'id_token'])
            ->all();
        $config = $this->integration->config ?? [];
        unset($config['access_token_expires_at']);
        $this->integration->config = $config;
        $this->integration->save();
    }

    private function fetchRoles(string $accessToken): array
    {
        $response = $this->send('GET', '/Authenticate/Roles', [], $accessToken);

        if (! $response->successful()) {
            throw $this->failure($response, 'Cloud Factory role discovery failed.');
        }

        $payload = $response->json() ?? [];
        $items = is_array(data_get($payload, 'roles')) ? data_get($payload, 'roles') : $payload;

        return collect(is_array($items) ? $items : [])
            ->map(function (mixed $item): ?string {
                if (is_string($item)) {
                    return trim($item);
                }

                if (! is_array($item)) {
                    return null;
                }

                $name = collect([
                    data_get($item, 'name'),
                    data_get($item, 'roleName'),
                    data_get($item, 'role'),
                    data_get($item, 'role.name'),
                ])->first(fn (mixed $value): bool => is_string($value) && filled($value));

                return is_string($name) ? trim($name) : null;
            })
            ->filter()
            ->unique(fn (string $role): string => strtolower($role))
            ->values()
            ->all();
    }

    private function storeRoleSnapshot(array $roles): array
    {
        $capabilities = $this->capabilities($roles);
        $config = $this->integration->config ?? [];
        $config['roles'] = $roles;
        $config['roles_checked_at'] = now()->toIso8601String();
        $config['capabilities'] = $capabilities;
        unset($config['roles_last_error'], $config['roles_last_error_at']);
        $this->integration->forceFill(['config' => $config])->save();

        return $capabilities;
    }

    private function refreshRoleSnapshotAfterTokenExchange(string $accessToken): void
    {
        try {
            $roles = $this->fetchRoles($accessToken);
            $this->storeRoleSnapshot($roles);
            $this->audit->record('connection.capabilities_refreshed', $this->integration, metadata: [
                'roles' => $roles,
                'source' => 'token_exchange',
            ]);
        } catch (Throwable $exception) {
            // A temporary role endpoint failure must not block an otherwise valid API operation.
            $this->rememberRoleRefreshFailure($exception);
            $this->audit->record('connection.capabilities_refresh_failed', $this->integration, metadata: [
                'exception' => $exception::class,
                'source' => 'token_exchange',
            ]);
        }
    }

    private function rememberRoleRefreshFailure(Throwable $exception): void
    {
        $config = $this->integration->config ?? [];
        $config['roles_last_error'] = Str::limit($exception->getMessage(), 300);
        $config['roles_last_error_at'] = now()->toIso8601String();
        $this->integration->forceFill(['config' => $config])->save();
    }

    private function capabilities(array $roles): array
    {
        $has = fn (string $role): bool => collect($roles)->contains(
            fn (string $current): bool => strcasecmp($current, $role) === 0
        );

        return [
            'customers' => $has('Partner'),
            'catalogue' => $has('Partner'),
            'microsoft' => $has('Microsoft Full Access'),
            'adobe' => $has('Adobe'),
            'finance' => $has('Finance'),
            'notifications' => $has('Partner Admin'),
            'activity_log' => $has('Partner Admin'),
        ];
    }

    private function failure(Response $response, string $fallback): CloudFactoryApiException
    {
        $message = collect([
            $response->json('message'),
            $response->json('detail'),
            $response->json('title'),
        ])->first(fn (mixed $value): bool => is_string($value) && filled($value));

        return new CloudFactoryApiException(
            Str::limit($message ?: $fallback, 300),
            $response->status(),
            ['provider_status' => $response->status()]
        );
    }
}
