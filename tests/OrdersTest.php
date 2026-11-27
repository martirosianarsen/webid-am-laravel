<?php

namespace Webid\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Webid\Laravel\Jobs\SendOrderNotice;
use Webid\Laravel\Jobs\SendOrderStage;
use Webid\Laravel\Orders;
use Webid\Laravel\Webid;

class OrdersTest extends TestCase
{
    private const CHAT = 'https://chat.webid.test/api/v1/orders';

    private const NOTICES = 'https://chat.webid.test/api/v1/notices';

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
                str_starts_with($request->url(), self::CHAT), $request->url() === self::NOTICES => $this->chat($request),
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

    #[Test]
    public function a_stage_moved_at_once_waits_for_the_queue_only_while_webid_is_out_of_reach(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        app(Orders::class)->moveNow('A-77', 'shipped');
        $this->assertSame(['PUT', self::CHAT.'/A-77', ['stage' => 'on_the_way']], [$this->sent[0]->method(), $this->sent[0]->url(), $this->sent[0]->data()]);
        Queue::assertNothingPushed();

        $this->chatStatus = 503;
        app(Orders::class)->moveNow('A-77', 'delivered');
        Queue::assertPushed(SendOrderStage::class, fn (SendOrderStage $job): bool => [$job->reference, $job->stage] === ['A-77', 'delivered']);
    }

    #[Test]
    public function the_businesss_members_are_told_of_a_new_order_whoever_placed_it(): void
    {
        $page = 'https://shop.example.com/admin/orders/1024';

        // A guest, or a customer without webid: the "New orders" conversation.
        app(Orders::class)->placed(session()->driver(), '1024', 'processing', $page);
        $this->assertCount(1, $this->sent);
        $this->assertSame(['POST', self::NOTICES], [$this->sent[0]->method(), $this->sent[0]->url()]);
        $this->assertSame(['kind' => 'new_order', 'reference' => '1024', 'url' => $page], $this->sent[0]->data());
        $this->assertSame('Bearer site-token', $this->sent[0]->header('Authorization')[0]);

        // A customer signed in with webid: their own conversation, with the order itself; no second notice.
        $this->signedInWithWebid();
        app(Orders::class)->placed(session()->driver(), '1025', 'processing', 'https://shop.example.com/admin/orders/1025');
        $this->assertCount(2, $this->sent);
        $this->assertSame(self::CHAT, $this->sent[1]->url());
        $this->assertSame(['reference' => '1025', 'stage' => 'preparing', 'customer_token' => 'customer-token', 'url' => 'https://shop.example.com/admin/orders/1025'], $this->sent[1]->data());
    }

    #[Test]
    public function the_businesss_members_are_told_when_the_customer_cancels_an_order(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $page = 'https://shop.example.com/admin/orders/1024';

        $this->assertTrue(app(Orders::class)->cancelled('1024', $page));
        $this->assertSame(['POST', self::NOTICES, ['kind' => 'order_cancelled', 'reference' => '1024', 'url' => $page]], [$this->sent[0]->method(), $this->sent[0]->url(), $this->sent[0]->data()]);

        // webid out of reach: tried again on the queue, still as a cancellation.
        $this->chatStatus = 503;
        $this->assertFalse(app(Orders::class)->cancelled('1025', $page));
        Queue::assertPushed(SendOrderNotice::class, fn (SendOrderNotice $job): bool => [$job->reference, $job->kind] === ['1025', 'order_cancelled']);
        $this->chatStatus = 200;
        (new SendOrderNotice('1025', $page, 'order_cancelled'))->handle(app(Orders::class));
        $this->assertSame('order_cancelled', end($this->sent)->data()['kind']);
    }

    #[Test]
    public function a_notice_webid_could_not_be_reached_for_is_tried_again(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();
        $page = 'https://shop.example.com/admin/orders/1024';

        // webid out of reach: the order is never in the way, and the notice goes on the queue.
        $this->chatStatus = 503;
        $this->assertFalse(app(Orders::class)->announce('1024', $page));
        Queue::assertPushed(SendOrderNotice::class, fn (SendOrderNotice $job): bool => [$job->reference, $job->page] === ['1024', $page] && $job->tries > 1);

        // The same for a customer with webid whose order could not be added: the members still hear of it.
        $this->signedInWithWebid();
        app(Orders::class)->placed(session()->driver(), '1025', 'processing', $page);
        Queue::assertPushed(SendOrderNotice::class, 2);

        // What webid refuses (the chat is off for the site) is not asked again.
        $this->chatStatus = 403;
        $this->assertFalse(app(Orders::class)->announce('1026', $page));
        Queue::assertPushed(SendOrderNotice::class, 2);
        (new SendOrderNotice('1026', $page))->handle(app(Orders::class));

        // The job sends it, and fails (to be tried again) while webid is out of reach.
        $this->chatStatus = 200;
        (new SendOrderNotice('1024', $page))->handle(app(Orders::class));
        $this->assertSame(['kind' => 'new_order', 'reference' => '1024', 'url' => $page], end($this->sent)->data());
        $this->chatStatus = 503;
        $this->expectException(RequestException::class);
        (new SendOrderNotice('1024', $page))->handle(app(Orders::class));
    }
}
