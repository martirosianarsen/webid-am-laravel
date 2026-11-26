<?php

namespace Webid\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\Jobs\SendOrderStage;
use Webid\Laravel\Orders;
use Webid\Laravel\Webid;

class OrdersTest extends TestCase
{
    private const CHAT = 'https://chat.webid.test/api/v1/orders';

    /** @var list<Request> What reached the pretend chat. */
    private array $sent = [];

    private int $chatStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();
        config(['webid.orders.stages' => ['processing' => 'preparing', 'shipped' => 'on_the_way']]);
        Http::fake(function (Request $request) {
            return match (true) {
                str_ends_with($request->url(), '/.well-known/openid-configuration') => Http::response($this->discovery()),
                str_ends_with($request->url(), '/oauth/token') => Http::response(['access_token' => 'site-token', 'expires_in' => 900]),
                str_starts_with($request->url(), self::CHAT) => $this->chat($request),
                default => Http::response([], 404),
            };
        });
    }

    private function chat(Request $request)
    {
        $this->sent[] = $request;

        return Http::response(['data' => []], $this->chatStatus);
    }

    private function signedInWithWebid(): void
    {
        session()->put(Webid::SESSION_TOKENS, ['access_token' => 'customer-token', 'expires_at' => time() + 600]);
    }

    #[Test]
    public function a_customer_signed_in_with_webid_gets_their_order_in_the_chat(): void
    {
        $this->signedInWithWebid();

        $this->assertTrue(app(Orders::class)->add(session()->driver(), '1024', 'processing'));

        $this->assertCount(1, $this->sent);
        [$request] = $this->sent;
        $this->assertSame(['POST', self::CHAT], [$request->method(), $request->url()]);
        $this->assertSame(['reference' => '1024', 'stage' => 'preparing', 'customer_token' => 'customer-token'], $request->data());
        $this->assertSame('Bearer site-token', $request->header('Authorization')[0], 'the site speaks with its own token');
    }

    #[Test]
    public function nothing_is_sent_without_webid_or_for_a_status_left_unmapped(): void
    {
        $this->assertFalse(app(Orders::class)->add(session()->driver(), '1024', 'processing'), 'a customer without webid');
        $this->signedInWithWebid();
        $this->assertFalse(app(Orders::class)->add(session()->driver(), '1024', 'awaiting_payment'));
        app(Orders::class)->move('1024', 'awaiting_payment');

        $this->assertSame([], $this->sent);
        $this->assertSame('delivered', app(Orders::class)->stage('delivered'), 'webid\'s own stages need no mapping');
    }

    #[Test]
    public function webid_out_of_reach_never_breaks_the_site(): void
    {
        $this->signedInWithWebid();
        $this->chatStatus = 503;

        $this->assertFalse(app(Orders::class)->add(session()->driver(), '1024', 'processing'));
        app(Orders::class)->move('1024', 'shipped');

        $this->assertCount(2, $this->sent);
    }

    #[Test]
    public function a_later_stage_goes_on_the_queue_and_is_retried(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        app(Orders::class)->move('A-77', 'shipped');

        Queue::assertPushed(SendOrderStage::class, fn (SendOrderStage $job): bool => [$job->reference, $job->stage] === ['A-77', 'on_the_way'] && $job->tries > 1);
        $this->assertSame([], $this->sent);

        // The job sends it; an order webid does not have is let be, an unreachable webid is tried again.
        (new SendOrderStage('A-77', 'on_the_way'))->handle(app(Orders::class));
        $this->assertSame(['PUT', self::CHAT.'/A-77', ['stage' => 'on_the_way']], [$this->sent[0]->method(), $this->sent[0]->url(), $this->sent[0]->data()]);
        $this->chatStatus = 404;
        (new SendOrderStage('A-77', 'on_the_way'))->handle(app(Orders::class));
        $this->chatStatus = 503;
        $this->expectException(RequestException::class);
        (new SendOrderStage('A-77', 'on_the_way'))->handle(app(Orders::class));
    }
}
