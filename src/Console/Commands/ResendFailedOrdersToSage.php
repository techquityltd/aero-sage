<?php

namespace Techquity\Aero\Sage\Console\Commands;

use Aero\Cart\Models\Order;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Techquity\Aero\Sage\Jobs\SendSalesOrder;

class ResendFailedOrdersToSage extends Command
{
    protected $signature = 'sage:resend-failed-orders {--date=} {--debug}';

    protected $description = 'Resends failed orders to Sage';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : now()->subDays(7);
        $debug = $this->option('debug');

        $orders = Order::whereNotNull('ordered_at')
            ->where('ordered_at', '>', $date)
            ->get()
            ->reject(function (Order $order) {
                return $order->additional('sage_order_ref');
            });

        $wording = $debug ? "Found" : "Resending";

        $this->info("{$wording} {$orders->count()} failed orders");

        if ($debug) {
            dd($orders->pluck('id'));
        }

        $orders->each(function (Order $order) {
            SendSalesOrder::dispatch($order)->onQueue('sage_50');
        });

        $this->output->success('Complete ✔');
    }
}
