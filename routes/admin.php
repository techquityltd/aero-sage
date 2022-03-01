<?php

use Techquity\Aero\Sage\Http\Controllers\SageOrdersController;

Route::get('sage/order/send/{order}', [SageOrdersController::class, 'store'])->name('admin.sage.orders.send');
Route::get('sage/order/delete/{order}', [SageOrdersController::class, 'destroy'])->name('admin.sage.orders.delete');
