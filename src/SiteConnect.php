<?php

namespace Webid\Laravel;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connecting the site to webid in one click, from the site's own admin. Instead of copying a connection key:
 *
 *     return redirect()->away(app(SiteConnect::class)->url(session()->driver(), route('admin.webid')));
 *
 * The administrator chooses the business on webid and confirms. webid proves the domain by asking this site for the
 * state at /.well-known/webid-connect/<state> (the package answers there), turns sign-in on and sends the person back
 * to /webid/connected with a one-time code; the package trades it for the connection key, server to server, and hands
 * the key to the site to keep:
 *
 *     SiteConnect::keepKeyUsing(fn (string $key) => Settings::put('webid_key', $key));   // e.g. in a service provider
 *
 * The site then goes back to the page given to url(), with session('webid_site_connect') = 'connected' or an error.
 */
class SiteConnect
{
    /** What the person who started connecting keeps in their session: the state and the page to come back to. */
    public const SESSION = 'webid.site_connect';

    /** Where the result is flashed for the page the person comes back to. */
    public const RESULT = 'webid_site_connect';

    /** Minutes the site answers webid with a state. */
    public const MINUTES = 30;

    /** @var (Closure(string): void)|null */
    private static ?Closure $keep = null;

    public function __construct(private Http $http, private Cache $cache, private string $webidUrl) {}

    /**
     * How the site keeps the connection key it receives (encrypted, in its settings). Without it nothing can be kept.
     *
     * @param  Closure(string): void  $keep
     */
    public static function keepKeyUsing(Closure $keep): void
    {
        self::$keep = $keep;
    }

    public static function canKeepKey(): bool
    {
        return self::$keep !== null;
    }

    /**
     * webid's page for connecting this site, with a new state kept in this session; $after is the page of the site
     * to come back to, $name the name webid suggests for a new business.
     */
    public function url(Session $session, string $after, ?string $name = null): string
    {
        $state = Str::random(48);
        $session->put(self::SESSION, ['state' => $state, 'after' => $after]);
        $this->cache->put(self::cacheKey($state), true, now()->addMinutes(self::MINUTES));

        return $this->businessUrl().'/connect?'.http_build_query([
            'site' => self::origin(), 'return' => self::origin().route('webid.site-connected', [], false), 'state' => $state, 'name' => $name ?? config('app.name'),
        ]);
    }

    /**
     * Whether this site started connecting with this state (webid asks before it connects anything).
     */
    public function started(string $state): bool
    {
        return $this->cache->has(self::cacheKey($state));
    }

    /**
     * Back from webid: the code becomes the connection key, which the site keeps. Only in the session that started.
     *
     * @return string the page to go back to
     */
    public function finish(Session $session, ?string $code, ?string $state, ?string $error): string
    {
        $started = $session->pull(self::SESSION);
        if (! is_array($started) || ! is_string($state) || ! hash_equals((string) $started['state'], $state)) {
            throw new RuntimeException('This return from webid was not started here: press "Connect to webid" again.');
        }
        $this->cache->forget(self::cacheKey($state));
        if ($error !== null || $code === null) {
            throw new RuntimeException($error === 'access_denied' ? 'Connecting was cancelled on webid.' : 'webid did not connect the site.');
        }
        if (self::$keep === null) {
            throw new RuntimeException('The site keeps no connection key: call SiteConnect::keepKeyUsing().');
        }

        $response = $this->http->asForm()->acceptJson()->timeout(10)
            ->post($this->businessUrl().'/connect/token', ['code' => $code, 'state' => $state, 'site' => self::origin()]);
        $key = $response->json('key');
        if (! $response->successful() || ! is_string($key)) {
            throw new RuntimeException('webid did not give the connection key: '.($response->json('error') ?? $response->status()));
        }
        ConnectionKey::parse($key);
        (self::$keep)($key);

        return (string) $started['after'];
    }

    /**
     * webid's business pages: business.<webid's host> (https://webid.am → https://business.webid.am).
     */
    private function businessUrl(): string
    {
        $parts = parse_url(rtrim($this->webidUrl, '/'));

        return ($parts['scheme'] ?? 'https').'://business.'.($parts['host'] ?? 'webid.am').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * This site's address as webid knows it: the scheme, host and port of APP_URL.
     */
    private static function origin(): string
    {
        $parts = parse_url((string) config('app.url'));

        return strtolower(($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function cacheKey(string $state): string
    {
        return 'webid.site_connect.'.hash('sha256', $state);
    }
}
