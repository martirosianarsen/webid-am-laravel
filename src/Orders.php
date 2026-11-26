<?php

namespace Webid\Laravel;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Throwable;
use Webid\Laravel\Jobs\SendOrderStage;

/**
 * Tells a customer where their order stands, in their chat with the business: the order number and webid's stage
 * (accepted, preparing, on_the_way, delivered, cancelled), never what was ordered. The site's own statuses are mapped
 * onto those stages in config/webid.php ("orders.stages"); a status that is not mapped is not sent.
 *
 * Only for customers signed in with webid, and never in the way of the site: nothing here throws. An order is added
 * while its customer is here (add(), with their webid token); its later stages go from anywhere (move()), queued and
 * retried while webid cannot be reached.
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
    public function add(Session $session, string $reference, string $status): bool
    {
        $stage = $this->stage($status);
        $token = $stage === null ? null : $this->webid->accessToken($session);
        if ($token === null) {
            return false;
        }
        try {
            $this->site()->post($this->webid->chatUrl().'/api/v1/orders', ['reference' => $reference, 'stage' => $stage, 'customer_token' => $token])->throw();

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
