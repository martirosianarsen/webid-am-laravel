<?php

namespace Webid\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\ConnectionKey;
use Webid\Laravel\Events\Connected;
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
    public function the_return_address_is_followed_only_to_a_page_of_the_site(): void
    {
        $this->fakeWebid(['sub' => 'user-1']);

        foreach (['https://evil.test/steal', '//evil.test', '/\\evil.test'] as $elsewhere) {
            $this->get('/webid/login?'.http_build_query(['return' => $elsewhere]))->assertRedirect()->assertSessionMissing('url.intended');
        }
        $this->get('/webid/login?return=/checkout')->assertRedirect()->assertSessionHas('url.intended', '/checkout');
    }

    #[Test]
    public function webid_signs_a_person_out_of_the_site_without_a_visit(): void
    {
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test User', 'email' => 'user@example.com', 'email_verified' => true]);
        $user = User::sole();
        $user->forceFill(['remember_token' => 'kept'])->save();
        $this->get('/webid/token')->assertOk();

        // A token that is not a logout token (an ID token, another site's, a forged one) changes nothing.
        $this->post('/webid/backchannel-logout', ['logout_token' => $this->idToken(['sub' => 'sub-1', 'nonce' => 'n'])])->assertStatus(400);
        $this->post('/webid/backchannel-logout', ['logout_token' => $this->idToken(['sub' => 'sub-1', 'aud' => 'another-site', 'events' => [Webid::LOGOUT_EVENT => []]])])->assertStatus(400);
        $this->post('/webid/backchannel-logout', ['logout_token' => 'not-a-token'])->assertStatus(400);
        $this->get('/webid/token')->assertOk();

        $this->post('/webid/backchannel-logout', ['logout_token' => $this->idToken(['sub' => 'sub-1', 'events' => [Webid::LOGOUT_EVENT => (object) []]])])->assertOk();

        $this->get('/webid/token')->assertStatus(401);
        $this->assertGuest();
        $this->assertNotSame('kept', $user->refresh()->remember_token, '"remember me" stops working on every device');

        // Signing in again afterwards holds.
        $this->claims = [];
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test User', 'email' => 'user@example.com', 'email_verified' => true]);
        $this->get('/webid/token')->assertOk();
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
    public function the_same_person_comes_back_to_the_same_user_and_an_equal_email_links_nobody(): void
    {
        // An account opened here with someone's address (the site cannot prove whose it is) is never taken over by it.
        $other = User::create(['name' => 'Someone else', 'email' => 'user@example.com', 'password' => 'x']);

        $this->signIn(['sub' => 'sub-1', 'name' => 'Test', 'email' => 'user@example.com', 'email_verified' => true]);
        $this->assertNull($other->refresh()->webid_sub);
        $user = User::firstWhere('webid_sub', 'sub-1');
        $this->assertFalse($user->is($other));
        $this->assertNull($user->email, 'the new user does not take an address another user has');
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->claims = [];
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test U.', 'email' => 'new@example.com', 'email_verified' => true]);
        $this->assertSame(2, User::count());
        $this->assertSame('Test U.', $user->refresh()->name);
    }

    /**
     * Starts linking the signed-in account and comes back from webid with a code.
     *
     * @param  array<string, mixed>  $claims
     */
    private function connect(array $claims, string $query = '')
    {
        if ($this->claims === []) {
            $this->fakeWebid($claims);
        }
        $location = $this->get('/webid/connect'.$query)->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
        $this->claims = [...$claims, 'nonce' => $params['nonce']];

        return $this->get('/webid/callback?'.http_build_query(['code' => 'the-code', 'state' => $params['state']]));
    }

    #[Test]
    public function a_person_signed_in_here_links_their_account_with_webid_themselves(): void
    {
        Event::fake([Connected::class, SignedIn::class]);
        $user = User::create(['name' => 'Local name', 'email' => 'user@example.com', 'password' => 'x']);
        $this->actingAs($user);

        $this->connect(['sub' => 'sub-1', 'name' => 'Test User'], '?return=/account')->assertRedirect('/account')->assertSessionHas('webid_status', 'connected');

        $this->assertSame('sub-1', $user->refresh()->webid_sub);
        $this->assertSame(1, User::count());
        $this->assertAuthenticatedAs($user);
        $this->get('/webid/token')->assertOk();
        Event::assertDispatched(Connected::class, fn (Connected $event): bool => $event->user->is($user) && $event->claims['sub'] === 'sub-1');
        Event::assertNotDispatched(SignedIn::class);

        // From now on signing in with webid comes back to this account.
        auth()->logout();
        $this->claims = [];
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test User']);
        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function linking_needs_a_signed_in_account_and_never_takes_another_ones_webid(): void
    {
        $this->fakeWebid(['sub' => 'sub-1']);
        $this->get('/webid/connect?return=/account')->assertRedirect(route('webid.login', ['return' => '/account']));

        $linked = User::create(['name' => 'Linked', 'email' => 'linked@example.com', 'password' => 'x', 'webid_sub' => 'sub-1']);
        $user = User::create(['name' => 'Local', 'email' => 'user@example.com', 'password' => 'x']);
        $this->actingAs($user);
        $this->connect(['sub' => 'sub-1'])->assertSessionHas('webid_error');
        $this->assertNull($user->refresh()->webid_sub, 'that webid account is already another user\'s here');
        $this->get('/webid/token')->assertUnauthorized();

        $user->forceFill(['webid_sub' => 'sub-9'])->save();
        $this->claims = [];
        $this->connect(['sub' => 'sub-2'])->assertSessionHas('webid_error');
        $this->assertSame('sub-9', $user->refresh()->webid_sub, 'an account linked to one webid account keeps it');
        $this->assertSame('sub-1', $linked->refresh()->webid_sub);
    }

    #[Test]
    public function a_link_started_by_one_account_never_lands_on_another(): void
    {
        $first = User::create(['name' => 'First', 'email' => 'first@example.com', 'password' => 'x']);
        $second = User::create(['name' => 'Second', 'email' => 'second@example.com', 'password' => 'x']);
        $this->fakeWebid(['sub' => 'sub-1']);
        $this->actingAs($first);
        $location = $this->get('/webid/connect')->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

        // Another account is signed in by the time the person comes back from webid.
        $this->actingAs($second);
        $this->claims = ['sub' => 'sub-1', 'nonce' => $params['nonce']];
        $this->get('/webid/callback?'.http_build_query(['code' => 'the-code', 'state' => $params['state']]))->assertSessionHas('webid_error');

        $this->assertNull($first->refresh()->webid_sub);
        $this->assertNull($second->refresh()->webid_sub);
    }

    #[Test]
    public function an_abandoned_link_does_not_turn_a_later_sign_in_into_one(): void
    {
        $user = User::create(['name' => 'Local', 'email' => 'user@example.com', 'password' => 'x']);
        $this->fakeWebid(['sub' => 'sub-1']);
        $this->actingAs($user)->get('/webid/connect')->assertRedirect();

        $this->claims = [];
        $this->signIn(['sub' => 'sub-1', 'name' => 'Test User']);

        $this->assertNull($user->refresh()->webid_sub);
        $this->assertAuthenticatedAs(User::firstWhere('webid_sub', 'sub-1'));
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
