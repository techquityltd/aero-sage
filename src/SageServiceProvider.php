<?php

namespace Techquity\Aero\Sage;

use Aero\Admin\AdminSlot;
use Aero\Cart\Events\OrderSuccessful;
use Aero\Common\Facades\Settings;
use Aero\Common\Providers\ModuleServiceProvider;
use Aero\Common\Settings\SettingGroup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Techquity\Aero\Sage\Jobs\UpdateProduct;
use Techquity\Aero\Sage\Listeners\CompletedOrder;
use Techquity\Aero\Sage\Services\SageManager;

class SageServiceProvider extends ModuleServiceProvider
{
    protected $listen = [
        OrderSuccessful::class => [
            CompletedOrder::class,
        ],
    ];

    public function register()
    {
        $this->app->singleton(
            SageManager::class,
            fn () => new SageManager(),
        );
    }

    public function setup(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        Router::addAdminRoutes(__DIR__ . '/../routes/admin.php');

        Settings::group('sage_50', static function (SettingGroup $group) {
            $group->boolean('enabled');
            $group->string('auth_token');
            $group->string('port');
            $group->array('currencies')->associative()->default(['GBP' => 1]);
            $group->integer('def_tax_code')->default(1);
            $group->string('def_nom_code')->default('4000');

            // Product import related settings
            $group->boolean('product_stock')
                ->hint('Update stock from Sage')
                ->default(true);

            $group->boolean('product_pricing')
                ->hint('Update products pricing from Sage')
                ->default(true);

            $group->boolean('product_detailed')
                ->hint('Update products with information such as weight')
                ->default(true);
        });

        AdminSlot::inject('orders.order.view.header.buttons', function ($data) {
            return view('admin::resource-lists.button', [
                'permission' => 'order.update-status',
                'link' => route('admin.sage.orders.send', [
                    'order' => $data['order']
                ]),
                'text' => 'Send to sage',
            ]);
        });

        $this->app->booted(static function () {
            $schedule = app(Schedule::class);

            $schedule->job(new UpdateProduct, 'sage_50')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
