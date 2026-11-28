<?php

namespace Webid\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\SiteConnect;

/**
 * Connecting the site in one click: the site sends its administrator to webid with a state, answers webid with that
 * state, and trades the code it comes back with for the connection key, which the site keeps.
 */
class SiteConnectTest extends TestCase
{
    /** @var list<string> */
    private array $kept = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://shop.test', 'webid.url' => 'https://webid.test']);
        $this->app->forgetInstance(SiteConnect::class);
        SiteConnect::keepKeyUsing(fn (string $key) => $this->kept[] = $key);
    }

    /**
     * @return array<string, string>
     */
    private function start(): array
    {
        $url = app(SiteConnect::class)->url(session()->driver(), '/admin/webid', 'Shop');
        $this->assertStringStartsWith('https://business.webid.test/connect?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    #[Test]
    public function the_site_answers_webid_and_keeps_the_key_it_comes_back_with(): void
    {
        $query = $this->start();
        $this->assertSame(['https://shop.test', 'https://shop.test/webid/connected', 'Shop'], [$query['site'], $query['return'], $query['name']]);

        // webid asks the site for the state: only the one it started with is answered.
        $this->get('/.well-known/webid-connect/'.$query['state'])->assertOk()->assertContent($query['state']);
        $this->get('/.well-known/webid-connect/'.str_repeat('x', 48))->assertNotFound();

        Http::fake(['business.webid.test/connect/token' => Http::response(['key' => self::connectionKey()])]);
        $this->get('/webid/connected?code=the-code&state='.$query['state'])
            ->assertRedirect('/admin/webid')->assertSessionHas(SiteConnect::RESULT, 'connected');

        $this->assertSame([self::connectionKey()], $this->kept);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://business.webid.test/connect/token'
            && $request['code'] === 'the-code' && $request['state'] === $query['state'] && $request['site'] === 'https://shop.test');

        // Done: the state is answered no more, and coming back again changes nothing.
        $this->get('/.well-known/webid-connect/'.$query['state'])->assertNotFound();
        $this->get('/webid/connected?code=the-code&state='.$query['state'])->assertSessionHas(SiteConnect::RESULT, fn ($message) => $message !== 'connected');
        $this->assertCount(1, $this->kept);
    }

    #[Test]
    public function a_return_not_started_here_cancelled_or_refused_keeps_nothing(): void
    {
        Http::fake(['business.webid.test/connect/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        // Another session's state.
        $this->get('/webid/connected?code=c&state='.str_repeat('y', 48))->assertSessionHas(SiteConnect::RESULT, fn ($message) => $message !== 'connected');

        $query = $this->start();
        $this->get('/webid/connected?error=access_denied&state='.$query['state'])
            ->assertRedirect('/admin/webid')->assertSessionHas(SiteConnect::RESULT, 'Connecting was cancelled on webid.');

        $query = $this->start();
        $this->get('/webid/connected?code=c&state='.$query['state'])->assertSessionHas(SiteConnect::RESULT, fn ($message) => str_contains($message, 'invalid_grant'));

        $this->assertSame([], $this->kept);
    }
}
