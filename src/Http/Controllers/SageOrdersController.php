<?php

namespace Techquity\Aero\Sage\Http\Controllers;

use Aero\Cart\Models\Order;
use Aero\Admin\Http\Controllers\Controller;
use Techquity\Aero\Sage\Jobs\SendSalesOrder;

class SageOrdersController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Order $order)
    {
        SendSalesOrder::dispatchNow($order);

        return redirect()->back();
    }
}
