<?php

namespace Techquity\Aero\Sage;

use Aero\Admin\AdminSlot;
use Aero\Cart\Events\OrderSuccessful;
use Aero\Common\Facades\Settings;
use Aero\Common\Providers\ModuleServiceProvider;
use Aero\Common\Settings\SettingGroup;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Techquity\Aero\Sage\Console\Commands\UpdateProducts;
use Techquity\Aero\Sage\Jobs\UpdateProduct;
use Techquity\Aero\Sage\Listeners\CompletedOrder;
use Illuminate\Support\Facades\Log;

class SageServiceProvider extends ModuleServiceProvider
{
    protected $listen = [
        OrderSuccessful::class => [
            CompletedOrder::class,
        ],
    ];

    public function boot(){
        parent::boot();

        $this->commands([
            UpdateProducts::class
        ]);
    }

    public function setup(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        Router::addAdminRoutes(__DIR__ . '/../routes/admin.php');

        Settings::group('sage_50', static function (SettingGroup $group) {
            $group->boolean('enabled');
            $group->boolean('superfluous_logging');
            $group->boolean('debug_mode')->default('false')->hint('Logs all responses and requests');
            $group->string('api_url')->default('[abc].hypersage.co.uk');
            $group->string('auth_token');
            $group->string('port');
            $group->array('currencies')->associative()->default(['GBP' => 1]);
            $group->integer('def_tax_code')->default(1);
            $group->string('def_nom_code')->default('4000');

            $group->string('heartbeat_api_key');
            $group->string('heartbeat_url');
            $group->boolean('heartbeat_logging')->default('false');

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
                'text' => 'Send To Sage',
            ]);
        });

        AdminSlot::inject('orders.order.view.header.buttons', function ($data) {
            if ($data['order']->additional('sage_order_ref')) {
                return view('admin::resource-lists.button', [
                    'permission' => 'order.update-status',
                    'link' => route('admin.sage.orders.delete', [
                        'order' => $data['order']
                    ]),
                    'text' => 'Delete From Sage',
                ]);
            }
        });

        $this->app->booted(static function () {
            $schedule = app(Schedule::class);

            $schedule->job(new UpdateProduct, 'sage_50_import')
                ->everyFiveMinutes()
                ->withoutOverlapping()
                ->onSuccess(function () {
                    if(setting('sage_50.heartbeat_logging')) {
                        Log::debug('Sage Update Products Executed');
                    }
                    if(setting('sage_50.heartbeat_url')){

                        $client = new \GuzzleHttp\Client();
                        $url = setting('sage_50.heartbeat_url');
                        $token = setting('sage_50.heartbeat_api_key');

                        $headers = [
                            'Authorization' => "Bearer $token",
                        ];

                        $request = new \GuzzleHttp\Psr7\Request('GET', $url, $headers);

                        $client->sendAsync($request)->wait();
                    }
                });
        });
    }
}
