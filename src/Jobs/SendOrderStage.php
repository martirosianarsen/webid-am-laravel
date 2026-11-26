<?php

namespace Webid\Laravel\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Webid\Laravel\Orders;

/**
 * An order's new stage, on its way to webid: tried again for about an hour while webid cannot be reached.
 */
class SendOrderStage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public string $reference, public string $stage) {}

    public function handle(Orders $orders): void
    {
        $orders->send($this->reference, $this->stage);
    }
}
