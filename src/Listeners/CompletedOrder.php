<?php

namespace Techquity\Aero\Sage\Listeners;

use Aero\Cart\Events\OrderSuccessful;
use Techquity\Aero\Sage\Jobs\SendSalesOrder;

class CompletedOrder
{
    public function handle(OrderSuccessful $event): void
    {
        SendSalesOrder::dispatch($event->order)->onQueue('sage_50');
    }
}
