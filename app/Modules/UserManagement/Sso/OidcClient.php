<?php

namespace App\Modules\UserManagement\Sso;

use Illuminate\Support\Facades\Http;
use Jumbojett\OpenIDConnectClient;
use Throwable;

/** Adapt the maintained OIDC verifier to Laravel transport and strict local policy. */
class OidcClient extends OpenIDConnectClient
{
    private string $expectedNonce = '';

    private array $metadata;

    private SsoProvider $provider;

    public function __construct(SsoProvider $provider)
    {
        $this->provider = $provider;
        parent::__construct($provider->issuer, $provider->client_id, $provider->client_secret);
        $this->setIssuerValidator(fn (string $issuer): bool => $issuer === $provider->issuer);
        $this->metadata = $this->discover();
        $this->providerConfigParam($this->metadata);
    }

    public static function assertIssuer(string $issuer): void
    {
        $url = parse_url($issuer);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || empty($url['host']) || isset($url['user'], $url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || isset($url['user']) || isset($url['pass'])
            || (isset($url['port']) && $url['port'] !== 443)
            || filter_var($url['host'], FILTER_VALIDATE_IP)
            || ! str_contains($url['host'], '.') || str_ends_with($issuer, '/')) {
            throw new SsoFailure;
        }
    }

    private function trustedEndpoint(string $url): void
    {
        $expected = parse_url($this->provider->issuer);
        $actual = parse_url($url);
        if (! is_array($actual) || ($actual['scheme'] ?? '') !== 'https'
            || ($actual['host'] ?? '') !== $expected['host']
            || ($actual['port'] ?? 443) !== ($expected['port'] ?? 443)
            || isset($actual['user']) || isset($actual['pass']) || isset($actual['fragment'])) {
            throw new SsoFailure;
        }
    }

    private function discover(): array
    {
        self::assertIssuer($this->provider->issuer);
        $data = json_decode($this->fetchURL($this->provider->issuer.'/.well-known/openid-configuration'), true, 32, JSON_THROW_ON_ERROR);
        if (($data['issuer'] ?? null) !== $this->provider->issuer
            || ! in_array('S256', $data['code_challenge_methods_supported'] ?? [], true)
            || ! in_array('code', $data['response_types_supported'] ?? [], true)) {
            throw new SsoFailure;
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'end_session_endpoint'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new SsoFailure;
            }
            $this->trustedEndpoint($data[$key]);
        }

        return $data;
    }

    protected function fetchURL(string $url, ?string $post_body = null, array $headers = [])
    {
        $this->trustedEndpoint($url);
        // Only metadata and JWKS use the library transport. Never follow a credential redirect.
        if ($post_body !== null) {
            throw new SsoFailure;
        }
        $response = Http::acceptJson()->withoutRedirecting()->connectTimeout(5)->timeout(15)->get($url);
        if (! $response->successful() || strlen($response->body()) > 1048576) {
            throw new SsoFailure;
        }

        return $response->body();
    }

    protected function getNonce()
    {
        return $this->expectedNonce;
    }

    public function authorizationUrl(array $attempt): string
    {
        return $this->metadata['authorization_endpoint'].'?'.http_build_query([
            'client_id' => $this->provider->client_id, 'response_type' => 'code',
            'redirect_uri' => SsoProvider::endpoint('sso.callback'), 'scope' => 'openid',
            'state' => $attempt['state'], 'nonce' => $attempt['nonce'],
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $attempt['verifier'], true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            // Linking always makes the selected work account explicit.
            'prompt' => $attempt['mode'] === 'link' ? 'login' : 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, array $attempt): object
    {
        try {
            $response = Http::acceptJson()->asForm()->withoutRedirecting()->connectTimeout(5)->timeout(15)
                ->withBasicAuth($this->provider->client_id, $this->provider->client_secret)
                ->post($this->metadata['token_endpoint'], [
                    'grant_type' => 'authorization_code', 'code' => $code,
                    'redirect_uri' => SsoProvider::endpoint('sso.callback'), 'code_verifier' => $attempt['verifier'],
                ]);
            if (! $response->successful() || strlen($response->body()) > 1048576) {
                throw new SsoFailure;
            }
            $tokens = $response->json();
            if (! is_string($tokens['id_token'] ?? null)) {
                throw new SsoFailure;
            }
            $this->expectedNonce = $attempt['nonce'];
            $this->idToken = $tokens['id_token'];
            $claims = $this->validated($tokens['id_token']);
            if (! is_string($claims->sub ?? null) || $claims->sub === '' || strlen($claims->sub) > 255
                || ! is_string($claims->nonce ?? null) || ! hash_equals($this->expectedNonce, $claims->nonce)
                || ! is_int($claims->exp ?? null) || $claims->exp <= time()
                || ! $this->verifyJWTClaims($claims, $tokens['access_token'] ?? null)) {
                throw new SsoFailure;
            }
            if (isset($claims->sid) && (! is_string($claims->sid) || $claims->sid === '' || strlen($claims->sid) > 500)) {
                throw new SsoFailure;
            }

            return $claims;
        } catch (Throwable) {
            throw new SsoFailure;
        }
    }

    /** Validate signed bytes before using any identity, including logout tokens. */
    public function validated(string $token): object
    {
        try {
            if (strlen($token) > 32768 || count(explode('.', $token)) !== 3) {
                throw new SsoFailure;
            }
            $header = $this->decodeJWT($token);
            if (! is_object($header) || ($header->alg ?? '') !== 'RS256'
                || isset($header->jwk) || isset($header->jku) || isset($header->x5u)
                || ! $this->verifyJWTSignature($token)) {
                throw new SsoFailure;
            }
            $claims = $this->decodeJWT($token, 1);
            $aud = $claims->aud ?? null;
            if (! is_object($claims) || ($claims->iss ?? '') !== $this->provider->issuer
                || ! (is_string($aud) || is_array($aud))
                || ! in_array($this->provider->client_id, is_array($aud) ? $aud : [$aud], true)
                || (is_array($aud) && count($aud) > 1 && ($claims->azp ?? null) !== $this->provider->client_id)
                || (isset($claims->azp) && $claims->azp !== $this->provider->client_id)
                || ! is_int($claims->iat ?? null) || $claims->iat > time() + 30
                || (isset($claims->nbf) && (! is_int($claims->nbf) || $claims->nbf > time() + 30))
                || (isset($claims->exp) && (! is_int($claims->exp) || $claims->exp <= time()))) {
                throw new SsoFailure;
            }

            return $claims;
        } catch (Throwable) {
            throw new SsoFailure;
        }
    }

    public function logoutClaims(string $token): object
    {
        $claims = $this->validated($token);
        if ($claims->iat < time() - 300 || isset($claims->nonce)
            || ! is_string($claims->jti ?? null) || $claims->jti === '' || strlen($claims->jti) > 500
            || ! isset($claims->events->{'http://schemas.openid.net/event/backchannel-logout'})
            || ! is_object($claims->events->{'http://schemas.openid.net/event/backchannel-logout'})
            || (! isset($claims->sub) && ! isset($claims->sid))
            || (isset($claims->sub) && (! is_string($claims->sub) || $claims->sub === '' || strlen($claims->sub) > 255))
            || (isset($claims->sid) && (! is_string($claims->sid) || $claims->sid === '' || strlen($claims->sid) > 500))
            || ! $this->verifyLogoutTokenClaims($claims)) {
            throw new SsoFailure;
        }

        return $claims;
    }

    public function logoutUrl(): string
    {
        return $this->metadata['end_session_endpoint'].'?'.http_build_query([
            'client_id' => $this->provider->client_id,
            'post_logout_redirect_uri' => SsoProvider::endpoint('login'),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
