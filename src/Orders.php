<?php

namespace Webid\Laravel;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;
use Webid\Laravel\Jobs\SendOrderNotice;
use Webid\Laravel\Jobs\SendOrderStage;

/**
 * Tells a customer where their order stands, in their chat with the business: the order number and webid's stage
 * (accepted, preparing, on_the_way, delivered, cancelled), never what was ordered. The site's own statuses are mapped
 * onto those stages in config/webid.php ("orders.stages"); a status that is not mapped is not sent.
 *
 * Only for customers signed in with webid, and never in the way of the site: nothing here throws. An order is added
 * while its customer is here (add(), with their webid token); its later stages go from anywhere (move()), queued and
 * retried while webid cannot be reached.
 *
 * It also tells the business's own members (those signed in with webid, in the chat inbox and on their devices) of a
 * new order, with a link to its page on this site: placed() for every order, whoever placed it. A customer signed in
 * with webid has it in their own conversation, where the members can answer them; a guest's, or a customer's without
 * webid, goes to the "New orders" conversation, which is read only.
 */
class Orders
{
    /** webid's stages. */
    public const STAGES = ['accepted', 'preparing', 'on_the_way', 'delivered', 'cancelled'];

    public function __construct(private Webid $webid, private Http $http) {}

    /**
     * Adds the order of the customer signed in with webid in this session (at checkout, or for their open orders
     * once they sign in with webid or connect their account: the SignedIn and Connected events). Returns whether
     * webid took it; false for a customer without webid, an unmapped status, or webid out of reach.
     */
    public function add(Session $session, string $reference, string $status, ?string $page = null): bool
    {
        $stage = $this->stage($status);
        $token = $stage === null ? null : $this->webid->accessToken($session);
        if ($token === null) {
            return false;
        }
        try {
            // With the order's page on this site, the business's members are told of it in this customer's conversation.
            $this->site()->post($this->webid->chatUrl().'/api/v1/orders', ['reference' => $reference, 'stage' => $stage, 'customer_token' => $token, ...($page === null ? [] : ['url' => $page])])->throw();

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Moves an order to the stage its new status maps to, from anywhere (an admin page, a job, a webhook). Sent on the
     * queue and retried; an order webid does not have (its customer is not on webid) is let be.
     */
    public function move(string $reference, string $status): void
    {
        $stage = $this->stage($status);
        if ($stage === null) {
            return;
        }
        if (config('queue.default') === 'sync') {
            try {
                $this->send($reference, $stage);
            } catch (Throwable $exception) {
                report($exception);
            }

            return;
        }
        dispatch(new SendOrderStage($reference, $stage));
    }

    /**
     * Moves an order at once, so its customer sees the new stage without waiting for the queue (for a site whose
     * queue runs from cron). Left to the queue, and retried, only while webid cannot be reached. Never throws.
     */
    public function moveNow(string $reference, string $status): void
    {
        $stage = $this->stage($status);
        if ($stage === null) {
            return;
        }
        try {
            $this->send($reference, $stage);
        } catch (Throwable $exception) {
            report($exception);
            if (config('queue.default') !== 'sync') {
                dispatch(new SendOrderStage($reference, $stage))->delay(30);
            }
        }
    }

    /**
     * Sends a stage now. Throws while webid cannot be reached (the job tries again); an order webid does not have, or
     * one that cannot move there any more (finished, or that would go back), is let be.
     */
    public function send(string $reference, string $stage): void
    {
        try {
            $this->site()->put($this->webid->chatUrl().'/api/v1/orders/'.rawurlencode($reference), ['stage' => $stage])->throw();
        } catch (RequestException $exception) {
            if (! in_array($exception->response->status(), [404, 422], true)) {
                throw $exception;
            }
        }
    }

    /**
     * A new order, whoever placed it: the business's members hear of it at once, with a link to its page on this site
     * ($page, on one of the site's own addresses). In the customer's own conversation when they are signed in with
     * webid (the order shows to the customer there as well, see add()); in the "New orders" conversation otherwise.
     */
    public function placed(Session $session, string $reference, string $status, string $page): void
    {
        if (! $this->add($session, $reference, $status, $page)) {
            $this->announce($reference, $page);
        }
    }

    /**
     * Tells the business's members of a new order that has no conversation of its own (a guest's, a customer's
     * without webid): in the "New orders" conversation of their inbox and on their devices, with a link to the order's
     * page on this site. Told twice, it shows once. Returns whether webid took it now; while webid cannot be reached
     * it is tried again on the queue. Never throws.
     */
    public function announce(string $reference, string $page): bool
    {
        try {
            $this->tell($reference, $page);

            return true;
        } catch (Throwable $exception) {
            report($exception);
            // What webid refused (the chat off for the site, a page elsewhere) would be refused again.
            $refused = $exception instanceof RequestException && $exception->response->clientError() && $exception->response->status() !== 429;
            if (! $refused && config('queue.default') !== 'sync') {
                dispatch(new SendOrderNotice($reference, $page))->delay(30);
            }

            return false;
        }
    }

    /**
     * Sends the notice of a new order now. Throws when webid did not take it.
     */
    public function tell(string $reference, string $page): void
    {
        $this->site()->post($this->webid->chatUrl().'/api/v1/notices', ['kind' => 'new_order', 'reference' => $reference, 'url' => $page])->throw();
    }

    /**
     * webid's stage for the site's own status: as mapped in config/webid.php, or the status itself when it already is
     * one of webid's stages.
     */
    public function stage(string $status): ?string
    {
        $stage = config('webid.orders.stages', [])[$status] ?? $status;

        return in_array($stage, self::STAGES, true) ? $stage : null;
    }

    private function site(): PendingRequest
    {
        return $this->http->acceptJson()->timeout(10)->withToken($this->webid->serviceToken('orders'));
    }
}
