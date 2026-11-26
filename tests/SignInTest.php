<?php

namespace Webid\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\ConnectionKey;
use Webid\Laravel\Events\SignedIn;
use Webid\Laravel\Tests\Fixtures\User;
use Webid\Laravel\Webid;

class SignInTest extends TestCase
{
    /** @var array<string, mixed> What the pretend webid puts in the next ID token. */
    private array $claims = [];

    /**
     * Pretends webid: discovery, keys, and a token endpoint answering with an ID token for $this->claims.
     *
     * @param  array<string, mixed>  $claims
     */
    private function fakeWebid(array $claims, int $expiresIn = 900): void
    {
        $this->claims = $claims;
        Http::fake(function (Request $request) use ($expiresIn) {
            $claims = $this->claims;

            return match (true) {
                str_ends_with($request->url(), '/.well-known/openid-configuration') => Http::response($this->discovery()),
                str_ends_with($request->url(), '/.well-known/jwks.json') => Http::response($this->jwks),
                str_ends_with($request->url(), '/oauth/token') => Http::response([
                    'access_token' => 'access-'.($request['grant_type'] ?? ''), 'refresh_token' => 'refresh', 'expires_in' => $expiresIn,
                    'id_token' => $this->idToken(['nonce' => '', ...$claims]),
                ]),
                default => Http::response(['message' => 'not here'], 404),
            };
        });
    }

    /**
     * Starts signing in and comes back from webid with a code.
     *
     * @param  array<string, mixed>  $claims
     */
    private function signIn(array $claims)
    {
        if ($this->claims === []) {
            $this->fakeWebid($claims);
        }
        $location = $this->get('/webid/login')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->claims = [...$claims, 'nonce' => $query['nonce']];

        return $this->get('/webid/callback?'.http_build_query(['code' => 'the-code', 'state' => $query['state']]));
    }

    #[Test]
    public function the_connection_key_holds_everything_needed(): void
    {
        $key = ConnectionKey::parse(self::connectionKey());

        $this->assertSame([self::ISSUER, self::CLIENT, self::SECRET], [$key->issuer, $key->clientId, $key->clientSecret]);
        $this->assertSame('https://api.webid.test', $key->apiUrl());
        $this->expectException(InvalidArgumentException::class);
        ConnectionKey::parse('not-a-key');
    }

    #[Test]
    public function signing_in_starts_at_webid_with_pkce(): void
    {
        $this->fakeWebid([]);
        $location = $this->get('/webid/login')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(self::ISSUER.'/oauth/authorize?', $location);
        $this->assertSame([self::CLIENT, 'http://localhost/webid/callback', 'code', 'openid', 'S256'],
            [$query['client_id'], $query['redirect_uri'], $query['response_type'], $query['scope'], $query['code_challenge_method']]);
        $this->assertNotEmpty($query['code_challenge']);
    }

    #[Test]
    public function a_new_visitor_becomes_a_user_and_is_signed_in(): void
    {
        Event::fake([SignedIn::class]);

        $this->signIn(['sub' => 'sub-1', 'name' => 'Test User', 'email' => 'user@example.com', 'email_verified' => true])->assertRedirect('/');

        $user = User::sole();
        $this->assertSame(['sub-1', 'Test User', 'user@example.com'], [$user->webid_sub, $user->name, $user->email]);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('access-authorization_code', session(Webid::SESSION_TOKENS)['access_token']);
        Event::assertDispatched(SignedIn::class, fn (SignedIn $event): bool => $event->user->is($user) && $event->claims['sub'] === 'sub-1');
        $this->get('/webid/token')->assertOk()->assertJsonPath('access_token', 'access-authorization_code');
    }

    #[Test]
    public function the_same_person_comes_back_to_the_same_user_and_a_confirmed_email_links_an_old_user(): void
    {
        $old = User::create(['name' => 'Old name', 'email' => 'user@example.com', 'password' => 'x']);

        $this->signIn(['sub' => 'sub-1', 'name' => 'Test', 'email' => 'user@example.com', 'email_verified' => true]);
        $this->assertSame('sub-1', $old->refresh()->webid_sub);
        $this->assertSame('Test', $old->name);

        auth()->logout();
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test U.', 'email' => 'new@example.com', 'email_verified' => true]);
        $this->assertSame(1, User::count());
        $this->assertSame('Test U.', $old->refresh()->name);

        auth()->logout();
        $this->signIn(['sub' => 'sub-2', 'name' => 'Someone', 'email' => 'user@example.com', 'email_verified' => false]);
        $this->assertSame(2, User::count(), 'an unconfirmed address links nobody');
        $this->assertNull(User::firstWhere('webid_sub', 'sub-2')->email, 'and the new user does not take an address another user has');
    }

    #[Test]
    public function a_sign_in_that_did_not_start_here_or_was_cancelled_signs_nobody_in(): void
    {
        $this->fakeWebid(['sub' => 'sub-1', 'name' => 'Test']);
        $this->get('/webid/callback?code=x&state=made-up')->assertRedirect('/')->assertSessionHas('webid_error');
        $this->get('/webid/callback?error=access_denied')->assertRedirect('/')->assertSessionHas('webid_error', 'access_denied');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function the_token_for_the_page_is_refreshed_when_it_ran_out(): void
    {
        $this->getJson('/webid/token')->assertUnauthorized();

        $this->signIn(['sub' => 'sub-1', 'name' => 'Test']);
        $this->withSession([Webid::SESSION_TOKENS => [...session(Webid::SESSION_TOKENS), 'expires_at' => time() - 1]]);
        $this->getJson('/webid/token')->assertOk()->assertJsonPath('access_token', 'access-refresh_token');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/token') && $request['grant_type'] === 'refresh_token'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode(self::CLIENT.':'.self::SECRET)));
    }

    #[Test]
    public function signing_out_ends_the_webid_session_too(): void
    {
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test']);
        $location = $this->post('/webid/logout')->assertRedirect()->headers->get('Location');

        $this->assertGuest();
        $this->assertStringStartsWith(self::ISSUER.'/oauth/logout?id_token_hint=', $location);
        $this->assertStringContainsString('post_logout_redirect_uri='.urlencode('http://localhost'), $location);
    }

    #[Test]
    public function the_site_reads_places_with_its_own_token(): void
    {
        Http::fake([
            self::ISSUER.'/.well-known/openid-configuration' => Http::response($this->discovery()),
            self::ISSUER.'/oauth/token' => Http::response(['access_token' => 'site-token', 'expires_in' => 900]),
            'https://api.webid.test/v1/places?parent=AM-ER' => Http::response(['data' => [['code' => 'am-er-kentron']]]),
            'https://api.webid.test/v1/places/nowhere' => Http::response(['message' => 'Not found'], 404),
        ]);
        $webid = app(Webid::class);

        $this->assertSame([['code' => 'am-er-kentron']], $webid->places(['parent' => 'AM-ER']));
        $this->assertNull($webid->place('nowhere'));
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/oauth/token') && $request['grant_type'] === 'client_credentials' && $request['scope'] === 'places');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/places') && $request->hasHeader('Authorization', 'Bearer site-token'));
    }
}
