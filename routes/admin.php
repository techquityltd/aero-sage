<?php

use Techquity\Aero\Sage\Http\Controllers\SageOrdersController;

Route::get('sage/order/send/{order}', SageOrdersController::class)->name('admin.sage.orders.send');
