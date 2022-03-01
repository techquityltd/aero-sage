<?php

namespace Techquity\Aero\Sage\Jobs;

use Aero\Cart\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Techquity\Aero\Sage\Factories\CustomerFactory;
use Techquity\Aero\Sage\Factories\SalesOrderFactory;

class SendSalesOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle(): void
    {
        // Turn an Aero order into a Sage customer.
        resolve(CustomerFactory::class, ['order' => $this->order]);

        // Turn an aero product into a Sage product.
        resolve(SalesOrderFactory::class, ['order' => $this->order]);
    }
}
