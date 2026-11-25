<?php

namespace Webid\Laravel;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;
use UnexpectedValueException;

/**
 * Talks to webid for the site: where its endpoints are, the sign-in address, the tokens (kept in the visitor's
 * session) and webid's APIs. Configured by the connection key alone.
 */
class Webid
{
    public const SESSION_TOKENS = 'webid.tokens';

    public function __construct(
        private ConnectionKey $key,
        private Http $http,
        private Cache $cache,
        private ?string $apiUrl = null,
        private ?string $chatUrl = null,
    ) {}

    /**
     * The chat widget's script, for <x-webid::chat />.
     */
    public function chatScriptUrl(): string
    {
        return rtrim($this->chatUrl ?: $this->key->chatUrl(), '/').'/widget.js';
    }

    public function key(): ConnectionKey
    {
        return $this->key;
    }

    /**
     * webid's OpenID Connect settings (endpoints, keys), kept for an hour.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        return $this->cache->remember('webid.discovery.'.md5($this->key->issuer), 3600, fn (): array => $this->http
            ->acceptJson()->timeout(10)->get($this->key->issuer.'/.well-known/openid-configuration')->throw()->json());
    }

    /**
     * Where to send the visitor to sign in, with PKCE, a state and a nonce kept in the session.
     */
    public function authorizationUrl(Session $session, string $redirectUri): string
    {
        $verifier = self::random(64);
        $state = self::random(32);
        $nonce = self::random(32);
        $session->put('webid.flow', ['verifier' => $verifier, 'state' => $state, 'nonce' => $nonce]);

        return $this->discovery()['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $this->key->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'openid',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::base64Url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Finishes signing in: checks the state, exchanges the code for tokens and checks the ID token.
     *
     * @return array{claims: array<string, mixed>, tokens: array<string, mixed>}
     */
    public function finish(Session $session, string $code, string $state, string $redirectUri): array
    {
        $flow = $session->pull('webid.flow');
        if (! is_array($flow) || ! hash_equals((string) $flow['state'], $state)) {
            throw new UnexpectedValueException('The sign-in did not start here or has expired.');
        }
        $tokens = $this->tokenRequest([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri, 'code_verifier' => $flow['verifier'],
        ]);
        $claims = $this->verifyIdToken((string) ($tokens['id_token'] ?? ''), (string) $flow['nonce']);
        $this->storeTokens($session, $tokens);

        return ['claims' => $claims, 'tokens' => $tokens];
    }

    /**
     * The signed-in visitor's access token for webid (for example for the chat widget), refreshed when it ran out.
     */
    public function accessToken(Session $session): ?string
    {
        $tokens = $session->get(self::SESSION_TOKENS);
        if (! is_array($tokens)) {
            return null;
        }
        if (($tokens['expires_at'] ?? 0) > time() + 30) {
            return $tokens['access_token'];
        }
        if (empty($tokens['refresh_token'])) {
            return null;
        }
        try {
            $fresh = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token']]);
        } catch (RuntimeException) {
            // webid no longer gives this site the visitor's data (or the visitor disconnected it): sign in again.
            $session->forget(self::SESSION_TOKENS);

            return null;
        }
        $this->storeTokens($session, $fresh + ['id_token' => $tokens['id_token'] ?? null]);

        return $fresh['access_token'];
    }

    /**
     * Where to send the visitor to sign out of webid too, coming back to the site.
     */
    public function logoutUrl(?string $idToken, string $returnTo): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        if (! is_string($endpoint) || $idToken === null) {
            return null;
        }

        return $endpoint.'?'.http_build_query(['id_token_hint' => $idToken, 'post_logout_redirect_uri' => $returnTo]);
    }

    /**
     * webid's shared list of places (the site needs the "Places" service on). For example places(['parent' => 'AM-ER'])
     * or place('am-er-kentron').
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    public function places(array $query = []): array
    {
        return $this->api('/v1/places', $query)['data'] ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function place(string $code): ?array
    {
        $response = $this->http->acceptJson()->timeout(10)->withToken($this->serviceToken('places'))->get($this->apiUrl().'/v1/places/'.rawurlencode($code));

        return $response->status() === 404 ? null : $response->throw()->json('data');
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function api(string $path, array $query): array
    {
        return $this->http->acceptJson()->timeout(10)->withToken($this->serviceToken('places'))->get($this->apiUrl().$path, $query)->throw()->json();
    }

    private function apiUrl(): string
    {
        return rtrim($this->apiUrl ?: $this->key->apiUrl(), '/');
    }

    /**
     * A token for the site itself (its client_id and secret), kept until shortly before it runs out.
     */
    private function serviceToken(string $scope): string
    {
        $cacheKey = 'webid.service_token.'.md5($this->key->clientId.$scope);
        $token = $this->cache->get($cacheKey);
        if (is_string($token)) {
            return $token;
        }
        $tokens = $this->tokenRequest(['grant_type' => 'client_credentials', 'scope' => $scope]);
        $this->cache->put($cacheKey, $tokens['access_token'], max(60, (int) ($tokens['expires_in'] ?? 900) - 60));

        return $tokens['access_token'];
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function tokenRequest(array $form): array
    {
        $response = $this->http->asForm()->acceptJson()->timeout(10)
            ->withBasicAuth(rawurlencode($this->key->clientId), rawurlencode($this->key->clientSecret))
            ->post($this->discovery()['token_endpoint'], $form);
        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new RuntimeException('webid refused the request: '.($response->json('error_description') ?? $response->json('error') ?? $response->status()));
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyIdToken(string $jwt, string $nonce): array
    {
        $keys = $this->cache->remember('webid.jwks.'.md5($this->key->issuer), 3600, fn (): array => $this->http
            ->acceptJson()->timeout(10)->get($this->discovery()['jwks_uri'])->throw()->json());
        $claims = json_decode(json_encode(JWT::decode($jwt, JWK::parseKeySet($keys))), true);
        $audience = (array) ($claims['aud'] ?? []);
        if (($claims['iss'] ?? null) !== $this->key->issuer || ! in_array($this->key->clientId, $audience, true) || ! hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new UnexpectedValueException('The ID token is not for this site.');
        }

        return $claims;
    }

    /**
     * @param  array<string, mixed>  $tokens
     */
    private function storeTokens(Session $session, array $tokens): void
    {
        $session->put(self::SESSION_TOKENS, [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'id_token' => $tokens['id_token'] ?? null,
            'expires_at' => time() + (int) ($tokens['expires_in'] ?? 900),
        ]);
    }

    private static function random(int $bytes): string
    {
        return self::base64Url(random_bytes($bytes));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
