<?php

namespace Techquity\Aero\Sage\Jobs;

use Aero\Cart\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DestroySalesOrder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function handle(): void
    {
        if ($reference = $this->order->additional('sage_order_ref')) {
            $client = new Client([
                'base_uri' => sprintf('https://nbu.hypersage.co.uk:%d/api/', setting('sage_50.port')),
                'http_errors' => false,
                'headers' => [
                    'AuthToken' => setting('sage_50.auth_token'),
                ],
            ]);

            $response = $client->request('delete', "salesOrder/{$reference}");

            $response = json_decode($response->getBody()->getContents(), true);

            if ($response['success']) {
                $this->order->additional('sage_order_ref', null);
            } else {
                Log::error($response['message'], [
                    'integration' => 'Sage 50',
                    'response' => $response
                ]);
            }
        }
    }
}
