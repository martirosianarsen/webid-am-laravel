<?php

namespace Webid\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Webid\Laravel\Orders;

/**
 * The notice of an order (a new one, or one its customer cancelled) webid could not be reached for: tried again for
 * about an hour. One webid refuses (the chat is off for the site, the page is not on the site) is let be.
 */
class SendOrderNotice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $reference, public string $page, public string $kind = 'new_order') {}

    public function handle(Orders $orders): void
    {
        try {
            $orders->tell($this->reference, $this->page, $this->kind);
        } catch (RequestException $exception) {
            if (! $exception->response->clientError() || $exception->response->status() === 429) {
                throw $exception;
            }
        }
    }
}
