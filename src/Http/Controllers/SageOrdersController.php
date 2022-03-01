<?php

namespace Techquity\Aero\Sage\Http\Controllers;

use Aero\Cart\Models\Order;
use Aero\Admin\Http\Controllers\Controller;
use Techquity\Aero\Sage\Jobs\DestroySalesOrder;
use Techquity\Aero\Sage\Jobs\SendSalesOrder;

class SageOrdersController extends Controller
{
    public function store(Order $order)
    {
        SendSalesOrder::dispatchNow($order);

        return redirect()->back();
    }

    public function destroy(Order $order)
    {
        DestroySalesOrder::dispatchNow($order);

        return redirect()->back();
    }
}
